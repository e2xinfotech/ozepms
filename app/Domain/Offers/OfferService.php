<?php

namespace App\Domain\Offers;

use App\Models\Offer;
use App\Models\Property;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one place where offers and promotions are decided and calculated (spec §32). Used by the
 * reservation pricing (StayPricer → PMS bookings, quotes, edits) and later the booking engine.
 *
 * Candidates: automatic offers (no promo code) always; a promo-code offer only when its code is entered.
 * An offer applies to a room when it is active and offered on the channel, matches the room type /
 * rate plan scope (no scope rows = every room), the booking date is in the booking window, the stay
 * length is within min/max nights, the days before arrival are within min/max advance days (early
 * bird / last minute), the booking total before discounts reaches min_amount, redemptions are left
 * and every extra condition holds (min_adults, min_rooms, source, guest_country). Only nights in the
 * stay window and on the selected weekdays are discounted.
 *
 * Discount types: percent of the night price; fixed_per_night (at most the night price);
 * fixed_per_stay (spread over the discounted nights by price); free_nights (stay X pay Y: per full
 * block of min_nights discounted nights, discount_value nights are free, the cheapest ones).
 *
 * Choice per room: a valid entered promo code first, then the highest priority, then the bigger
 * discount. Further offers are added only while every applied offer is stackable; each one works on
 * what is left of the night price, so a night never goes below zero. Discounts come before tax.
 */
class OfferService
{
    public const CONDITIONS = ['min_adults', 'min_rooms', 'source', 'guest_country'];

    /**
     * @param  array<int|string, array<string, mixed>>  $rooms  key => [room_type_id, rate_plan_id, check_in, check_out,
     *                                                         adults, nights => [date => price], fixed => list<date> (never discounted)]
     */
    public function evaluate(Property $property, OfferContext $context, array $rooms): OfferResult
    {
        $promo = $context->promo();
        if ($rooms === []) {
            return OfferResult::none($promo !== null ? ['code' => $promo, 'status' => 'not_eligible', 'reason' => 'offers.reasons.no_rooms'] : null);
        }
        $places = Money::minorUnits((string) $property->currency_code);
        $offers = $this->candidates($property, $context);
        $bookingTotal = Money::sum(array_map(fn ($r) => Money::sum(array_values($r['nights'])), $rooms));

        $out = [];
        $applied = [];
        $promoReason = null;
        $promoOffer = $promo !== null ? $offers->first(fn (Offer $o) => $o->promo_code !== null && strtoupper($o->promo_code) === $promo) : null;

        foreach ($rooms as $key => $room) {
            $choices = [];
            foreach ($offers as $offer) {
                $reason = $this->ineligible($offer, $room, $context, $bookingTotal, count($rooms));
                if ($reason !== null) {
                    if ($promoOffer !== null && $offer->id === $promoOffer->id) {
                        $promoReason ??= $reason;
                    }

                    continue;
                }
                $standalone = $this->discounts($offer, $room, $room['nights'], $places);
                if (Money::isPositive(Money::sum(array_values($standalone)))) {
                    $choices[] = [$offer, Money::sum(array_values($standalone))];
                } elseif ($promoOffer !== null && $offer->id === $promoOffer->id) {
                    $promoReason ??= 'offers.reasons.no_nights';
                }
            }
            usort($choices, function ($a, $b) use ($promoOffer) {
                $pa = $promoOffer !== null && $a[0]->id === $promoOffer->id ? 1 : 0;
                $pb = $promoOffer !== null && $b[0]->id === $promoOffer->id ? 1 : 0;

                return [$pb, $b[0]->priority, (float) $b[1], -$b[0]->id] <=> [$pa, $a[0]->priority, (float) $a[1], -$a[0]->id];
            });

            $left = $room['nights'];
            $roomOut = ['nights' => array_fill_keys(array_keys($room['nights']), '0.00'), 'offers' => []];
            $allStackable = true;
            foreach ($choices as $i => [$offer]) {
                if ($i > 0 && (! $allStackable || ! $offer->is_stackable)) {
                    continue;
                }
                $nightly = array_filter($this->discounts($offer, $room, $left, $places), fn ($d) => Money::isPositive($d));
                if ($nightly === []) {
                    continue;
                }
                foreach ($nightly as $date => $d) {
                    $left[$date] = Money::round(Money::sub($left[$date], $d), $places);
                    $roomOut['nights'][$date] = Money::round(Money::add($roomOut['nights'][$date], $d), $places);
                }
                $amount = Money::round(Money::sum(array_values($nightly)), $places);
                $roomOut['offers'][$offer->id] = ['amount' => $amount, 'nightly' => $nightly];
                $applied[$offer->id] ??= $this->summary($offer);
                $applied[$offer->id]['amount'] = Money::round(Money::add($applied[$offer->id]['amount'], $amount), $places);
                $allStackable = $allStackable && $offer->is_stackable;
            }
            $out[$key] = $roomOut;
        }

        $total = Money::round(Money::sum(array_column($applied, 'amount')), $places);
        $promoState = null;
        if ($promo !== null) {
            $promoState = match (true) {
                $promoOffer === null => ['code' => $promo, 'status' => 'unknown', 'reason' => 'offers.reasons.unknown_code'],
                isset($applied[$promoOffer->id]) => ['code' => $promo, 'status' => 'applied', 'reason' => null],
                default => ['code' => $promo, 'status' => 'not_eligible', 'reason' => $promoReason ?? 'offers.reasons.not_combinable'],
            };
        }

        return new OfferResult($out, array_values($applied), $total, $promoState);
    }

    /**
     * Counts one redemption per offer, inside the booking transaction. Refuses (field error) when a
     * limited offer was used up meanwhile, so a parallel booking can never exceed max_redemptions.
     *
     * @param  list<int>  $offerIds
     */
    public function redeem(array $offerIds, string $field = 'promo_code'): void
    {
        foreach (array_unique($offerIds) as $id) {
            $ok = DB::table('offers')->where('id', $id)
                ->where(fn ($q) => $q->whereNull('max_redemptions')->orWhereColumn('redemptions', '<', 'max_redemptions'))
                ->increment('redemptions');
            if ($ok === 0) {
                throw ValidationException::withMessages([$field => __('offers.reasons.redeemed')]);
            }
        }
    }

    /** Gives redemptions back (cancelled booking). @param list<int> $offerIds */
    public function release(array $offerIds): void
    {
        foreach (array_unique($offerIds) as $id) {
            DB::table('offers')->where('id', $id)->where('redemptions', '>', 0)->decrement('redemptions');
        }
    }

    /** Terms frozen on the booking (offer_applications.snapshot). @return array<string, mixed> */
    public function summary(Offer $offer): array
    {
        return [
            'offer_id' => $offer->id, 'id' => $offer->public_id, 'name' => $offer->name, 'code' => $offer->code,
            'promo_code' => $offer->promo_code, 'offer_type' => $offer->offer_type, 'discount_type' => $offer->discount_type,
            'discount_value' => Money::normalize((string) $offer->discount_value), 'min_nights' => $offer->min_nights,
            'stay_from' => $offer->stay_from?->toDateString(), 'stay_to' => $offer->stay_to?->toDateString(),
            'weekdays' => $offer->weekdays, 'is_stackable' => (bool) $offer->is_stackable, 'amount' => '0.00',
        ];
    }

    /**
     * Automatic offers that are active on the channel, plus every offer with the entered code (an
     * inactive or other-channel one is kept, flagged, so the code reads "not available", not "unknown").
     */
    private function candidates(Property $property, OfferContext $context): Collection
    {
        $promo = $context->promo();
        $channel = $context->channel === 'booking_engine' ? 'on_booking_engine' : 'on_pms';

        return Offer::acrossProperties()->where('property_id', $property->id)
            ->where(fn ($q) => $q->where(fn ($a) => $a->whereNull('promo_code')->where('is_active', true)->where($channel, true))
                ->when($promo !== null, fn ($w) => $w->orWhere('promo_code', $promo)))
            ->with(['scopes', 'conditions'])
            ->orderByDesc('priority')->orderBy('id')
            ->get()
            ->each(fn (Offer $o) => $o->setAttribute('_unavailable', ! $o->is_active || ! $o->{$channel}));
    }

    /** Why $offer does not apply to $room (a translation key), or null when it does. */
    private function ineligible(Offer $offer, array $room, OfferContext $context, string $bookingTotal, int $roomCount): ?string
    {
        if ($offer->getAttribute('_unavailable')) {
            return 'offers.reasons.inactive';
        }
        if ($offer->scopes->isNotEmpty() && ! $offer->scopes->contains(fn ($s) => ($s->room_type_id === null || (int) $s->room_type_id === (int) $room['room_type_id'])
            && ($s->rate_plan_id === null || (int) $s->rate_plan_id === (int) $room['rate_plan_id']))) {
            return 'offers.reasons.scope';
        }
        $booked = $context->bookedOn->toDateString();
        if (($offer->booking_from && $booked < $offer->booking_from->toDateString()) || ($offer->booking_to && $booked > $offer->booking_to->toDateString())) {
            return 'offers.reasons.booking_window';
        }
        /** @var CarbonImmutable $checkIn */
        $checkIn = $room['check_in'];
        $nights = count($room['nights']);
        if ($offer->min_nights !== null && $nights < $offer->min_nights) {
            return 'offers.reasons.min_nights';
        }
        if ($offer->max_nights !== null && $nights > $offer->max_nights) {
            return 'offers.reasons.max_nights';
        }
        $advance = (int) $context->bookedOn->startOfDay()->diffInDays($checkIn->startOfDay(), false);
        if ($offer->min_advance_days !== null && $advance < $offer->min_advance_days) {
            return 'offers.reasons.min_advance';
        }
        if ($offer->max_advance_days !== null && $advance > $offer->max_advance_days) {
            return 'offers.reasons.max_advance';
        }
        if ($offer->min_amount !== null && Money::compare($bookingTotal, (string) $offer->min_amount) < 0) {
            return 'offers.reasons.min_amount';
        }
        if ($offer->max_redemptions !== null && $offer->redemptions >= $offer->max_redemptions) {
            return 'offers.reasons.redeemed';
        }
        foreach ($offer->conditions as $c) {
            if (! $this->conditionHolds($c->condition_type, $c->operator, $c->value, $room, $context, $roomCount)) {
                return 'offers.reasons.condition_'.$c->condition_type;
            }
        }
        if ($this->eligibleDates($offer, $room) === []) {
            return 'offers.reasons.stay_window';
        }

        return null;
    }

    private function conditionHolds(string $type, string $operator, mixed $value, array $room, OfferContext $context, int $roomCount): bool
    {
        $actual = match ($type) {
            'min_adults' => (int) ($room['adults'] ?? 0),
            'min_rooms' => $roomCount,
            'source' => $context->sourceCode,
            'guest_country' => $context->guestCountry !== null ? strtoupper($context->guestCountry) : null,
            default => null,
        };
        $list = array_map(fn ($v) => is_string($v) ? strtoupper($v) : $v, (array) $value);
        $one = $list[0] ?? null;

        return match ($operator) {
            'gte' => $actual !== null && $actual >= (int) $one,
            'lte' => $actual !== null && $actual <= (int) $one,
            'eq' => $actual !== null && (is_string($actual) ? strtoupper($actual) : $actual) == $one,
            'neq' => (is_string($actual) ? strtoupper((string) $actual) : $actual) != $one,
            'in' => $actual !== null && in_array(is_string($actual) ? strtoupper($actual) : $actual, $list, false),
            'not_in' => ! in_array(is_string($actual) ? strtoupper((string) $actual) : $actual, $list, false),
            default => false,
        };
    }

    /** Nights of the room inside the stay window and on the offer's weekdays (not fixed). @return list<string> */
    private function eligibleDates(Offer $offer, array $room): array
    {
        $fixed = array_flip($room['fixed'] ?? []);
        $from = $offer->stay_from?->toDateString();
        $to = $offer->stay_to?->toDateString();
        $mask = $offer->weekdays ?: Offer::ALL_DAYS;
        $dates = [];
        foreach (array_keys($room['nights']) as $date) {
            if (isset($fixed[$date]) || ($from && $date < $from) || ($to && $date > $to)) {
                continue;
            }
            $bit = 1 << (CarbonImmutable::parse($date)->dayOfWeekIso - 1);
            if (($mask & $bit) === 0) {
                continue;
            }
            $dates[] = $date;
        }

        return $dates;
    }

    /**
     * Discount per night of $offer on $prices (what is left of each night).
     *
     * @param  array<string, string>  $prices
     * @return array<string, string>
     */
    private function discounts(Offer $offer, array $room, array $prices, int $places): array
    {
        $dates = array_values(array_filter($this->eligibleDates($offer, $room), fn ($d) => Money::isPositive($prices[$d] ?? '0')));
        if ($dates === []) {
            return [];
        }
        $value = (string) $offer->discount_value;
        $out = [];
        switch ($offer->discount_type) {
            case 'percent':
                foreach ($dates as $d) {
                    $out[$d] = Money::round(Money::min(Money::percent($prices[$d], $value), $prices[$d]), $places);
                }
                break;
            case 'fixed_per_night':
                foreach ($dates as $d) {
                    $out[$d] = Money::round(Money::min($value, $prices[$d]), $places);
                }
                break;
            case 'fixed_per_stay':
                $base = Money::sum(array_map(fn ($d) => $prices[$d], $dates));
                $total = Money::round(Money::min($value, $base), $places);
                $given = '0';
                foreach ($dates as $i => $d) {
                    $share = $i === count($dates) - 1
                        ? Money::sub($total, $given)
                        : Money::round(Money::div(Money::mul($total, $prices[$d]), $base), $places);
                    $share = Money::min(Money::max($share, '0'), $prices[$d]);
                    $out[$d] = Money::round($share, $places);
                    $given = Money::add($given, $out[$d]);
                }
                break;
            case 'free_nights':
                $block = max(1, (int) ($offer->min_nights ?? 1));
                $free = (int) floor(count($dates) / $block) * max(0, (int) floor((float) $value));
                if ($free > 0) {
                    $cheapest = $dates;
                    usort($cheapest, fn ($a, $b) => [(float) $prices[$a], $a] <=> [(float) $prices[$b], $b]);
                    foreach (array_slice($cheapest, 0, min($free, count($dates))) as $d) {
                        $out[$d] = Money::round($prices[$d], $places);
                    }
                }
                break;
        }

        return $out;
    }
}
