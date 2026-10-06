<?php

namespace App\Domain\BookingEngine;

use App\Domain\Accommodation\InProperty;
use App\Domain\Availability\AvailabilityService;
use App\Domain\Billing\PaymentService;
use App\Domain\Billing\Razorpay\RazorpayClient;
use App\Domain\Reservations\ReservationService;
use App\Domain\Reservations\StayPricer;
use App\Domain\Subscription\SubscriptionService;
use App\Models\Product;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Models\RoomTypeImage;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The public booking engine of one property (spec §33, §63): no login, the property code is the
 * only input. Everything is decided here on the server with the same engines as the PMS:
 * AvailabilityService (channel booking_engine), StayPricer + OfferService (offers, taxes) and
 * ReservationService (final availability check inside the booking transaction).
 *
 * Open when the property is active, its subscription is not read-only, its plan includes the
 * booking engine and the property has not switched it off (property_settings 'booking_engine').
 */
class BookingEngineService
{
    public const SETTINGS_KEY = 'booking_engine';

    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly StayPricer $pricer,
        private readonly ReservationService $reservations,
        private readonly PaymentService $payments,
        private readonly RazorpayClient $razorpay,
        private readonly SubscriptionService $subscriptions,
    ) {}

    /** The property behind a public code, only while its booking engine is open. */
    public function property(string $code): ?Property
    {
        $property = Property::query()->where('code', strtoupper($code))->first();

        return $property !== null && $this->isOpen($property) ? $property : null;
    }

    public function isOpen(Property $property): bool
    {
        if ($property->status !== 'active' || ! $this->settings($property)['enabled']) {
            return false;
        }
        $sub = $property->currentSubscription;
        if ($sub === null || $this->subscriptions->state($property)['read_only']) {
            return false;
        }

        return (bool) (($sub->plan?->features ?? [])['booking_engine'] ?? false);
    }

    /**
     * For the hotel's own settings screen: is the public page open, and if not, why.
     *
     * @return array{open: bool, reason: ?string, url: string}
     */
    public function status(Property $property): array
    {
        $sub = $property->currentSubscription;
        $reason = match (true) {
            $property->status !== 'active' => 'property_inactive',
            $sub === null || $this->subscriptions->state($property)['read_only'] => 'subscription',
            ! (bool) (($sub->plan?->features ?? [])['booking_engine'] ?? false) => 'plan',
            ! $this->settings($property)['enabled'] => 'switched_off',
            default => null,
        };

        return ['open' => $reason === null, 'reason' => $reason, 'url' => route('booking.search', ['code' => $property->code])];
    }

    /** @return array{enabled: bool, intro: ?string, terms: ?string} */
    public function settings(Property $property): array
    {
        $raw = DB::table('property_settings')->where('property_id', $property->id)->where('key', self::SETTINGS_KEY)->value('value');
        $v = $raw ? (json_decode((string) $raw, true) ?: []) : [];

        return ['enabled' => (bool) ($v['enabled'] ?? true), 'intro' => $v['intro'] ?? null, 'terms' => $v['terms'] ?? null];
    }

    /** @param array{enabled?: bool, intro?: ?string, terms?: ?string} $values */
    public function saveSettings(Property $property, array $values): array
    {
        $merged = array_merge($this->settings($property), array_intersect_key($values, array_flip(['enabled', 'intro', 'terms'])));
        DB::table('property_settings')->updateOrInsert(['property_id' => $property->id, 'key' => self::SETTINGS_KEY],
            ['value' => json_encode($merged, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);

        return $merged;
    }

    /** Public facts of the property for the page header (no internal ids). */
    public function profile(Property $property): array
    {
        $country = DB::table('countries')->where('iso2', $property->country_iso2)->value('name');

        return [
            'code' => $property->code, 'name' => $property->name, 'tagline' => $property->tagline, 'description' => $property->description,
            'address' => trim(implode(', ', array_filter([$property->address_line1, $property->city, $country]))),
            'phone' => $property->phone, 'email' => $property->email, 'website' => $property->website,
            'logo' => $property->logo_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($property->logo_path) : null,
            'stars' => $property->star_rating, 'currency' => $property->currency_code,
            'check_in_time' => substr((string) $property->check_in_time, 0, 5), 'check_out_time' => substr((string) $property->check_out_time, 0, 5),
            'today' => $this->today($property)->toDateString(),
            'online_payments' => $this->razorpay->enabled(),
            'limits' => ['max_nights' => (int) config('ozepms.booking_engine.max_nights'), 'max_days_ahead' => (int) config('ozepms.booking_engine.max_days_ahead'), 'max_rooms' => (int) config('ozepms.booking_engine.max_rooms')],
        ] + $this->settings($property);
    }

    public function today(Property $property): CarbonImmutable
    {
        return CarbonImmutable::now($property->timezone ?: config('app.timezone'))->startOfDay();
    }

    /**
     * Room types with their bookable rate plans for the search, cheapest first. Cached per
     * property, ARI version, offers, query and day.
     *
     * @param  array{check_in: string, check_out: string, adults: int, children: int, infants: int, rooms?: int, promo_code?: ?string}  $q
     */
    public function search(Property $property, array $q): array
    {
        [$in, $out] = $this->dates($property, $q);
        $q['promo_code'] = isset($q['promo_code']) && trim((string) $q['promo_code']) !== '' ? strtoupper(trim((string) $q['promo_code'])) : null;
        $rooms = max(1, min((int) ($q['rooms'] ?? 1), (int) config('ozepms.booking_engine.max_rooms')));
        $offersVersion = (string) DB::table('offers')->where('property_id', $property->id)->max('updated_at');
        $key = 'be:search:'.$property->id.':'.$property->fresh()->ari_version.':'.md5($offersVersion.json_encode([$in->toDateString(), $out->toDateString(), (int) $q['adults'], (int) $q['children'], (int) $q['infants'], $rooms, $q['promo_code'], $this->today($property)->toDateString(), app()->getLocale()]));

        return Cache::remember($key, (int) config('ozepms.booking_engine.cache_seconds'), fn () => InProperty::run($property,
            fn () => $this->runSearch($property, $in, $out, (int) $q['adults'], (int) $q['children'], (int) $q['infants'], $rooms, $q['promo_code'])));
    }

    /**
     * Books one room type + rate plan ($rooms rooms, same occupancy each) for a guest. Pay at the
     * property: confirmed. Prepaid / deposit: pending, holding the rooms while the guest pays online
     * (Razorpay), or pending for the property to follow up when online payment is not set up.
     *
     * @return array{reservation: Reservation, payment: ?array, due: string}
     */
    public function book(Property $property, array $data): array
    {
        [$in, $out] = $this->dates($property, $data);
        $count = max(1, min((int) ($data['rooms'] ?? 1), (int) config('ozepms.booking_engine.max_rooms')));

        return InProperty::run($property, function () use ($property, $data, $in, $out, $count) {
            $roomType = RoomType::query()->where('public_id', $data['room_type_id'])->where('is_active', true)->first();
            $plan = RatePlan::query()->where('public_id', $data['rate_plan_id'])->where('is_active', true)->where('sell_on_booking_engine', true)->first();
            $product = $roomType && $plan ? Product::query()->where('room_type_id', $roomType->id)->where('rate_plan_id', $plan->id)->where('is_active', true)->first() : null;
            if ($product === null) {
                throw ValidationException::withMessages(['rate_plan_id' => __('booking.errors.rate_unavailable')]);
            }
            $product->setRelation('ratePlan', $plan);
            $children = (int) ($data['children'] ?? 0);
            $infants = (int) ($data['infants'] ?? 0);
            $spec = [
                'id' => null, 'product' => $product, 'check_in' => $in, 'check_out' => $out, 'adults' => (int) $data['adults'],
                'children' => $children, 'infants' => $infants, 'child_ages' => [...array_fill(0, $children, 8), ...array_fill(0, $infants, 0)],
                'rate' => null, 'unit' => null,
            ];
            $online = $plan->payment_type !== 'pay_at_property' && $this->razorpay->enabled();
            $status = $plan->payment_type === 'pay_at_property' ? 'confirmed' : 'pending';
            $g = $data['guest'];

            $reservation = $this->reservations->create($property, [
                'status' => $status, 'channel' => 'booking_engine',
                'source_id' => DB::table('booking_sources')->whereNull('property_id')->where('code', 'booking_engine')->value('id'),
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'hold_minutes' => $online ? (int) config('ozepms.booking_engine.hold_minutes') : null,
                'quoted_total' => $data['quoted_total'] ?? null,
                'promo_code' => isset($data['promo_code']) && trim((string) $data['promo_code']) !== '' ? strtoupper(trim((string) $data['promo_code'])) : null,
                'special_requests' => $data['special_requests'] ?? null,
                'arrival_time' => $data['arrival_time'] ?? null,
                'guest' => [
                    'first_name' => $g['first_name'], 'last_name' => $g['last_name'] ?? null, 'email' => $g['email'],
                    'phone' => $g['phone'] ?? null, 'nationality_iso2' => $g['nationality_iso2'] ?? null, 'guest_type' => 'individual',
                ],
                'rooms' => array_fill(0, $count, $spec),
            ], null);
            $reservation = $reservation->fresh();

            $due = $this->amountDue($reservation, $plan);
            $payment = null;
            if ($online && Money::isPositive($due) && ! $this->reservations->replayed) {
                $p = $this->payments->startOnline($reservation, ['amount' => $due, 'idempotency_key' => 'be-'.$reservation->public_id], null);
                $payment = ['id' => $p->public_id ?? $p->id, 'checkout' => $this->payments->checkoutData($p, $reservation)];
            }

            return ['reservation' => $reservation, 'payment' => $payment, 'due' => $due];
        });
    }

    /** A pending online booking whose payment has arrived (checkout or webhook) is confirmed. */
    public function confirmIfPaid(Reservation $reservation): Reservation
    {
        if ($reservation->status !== 'pending') {
            return $reservation;
        }
        $paid = (string) DB::table('payments')->where('reservation_id', $reservation->id)->where('kind', 'payment')->where('status', 'captured')->sum('amount');
        if (! Money::isPositive($paid ?: '0')) {
            return $reservation;
        }
        $property = Property::query()->findOrFail($reservation->property_id);

        return InProperty::run($property, fn () => $this->reservations->confirm($reservation, null))->fresh();
    }

    /**
     * Pending booking-engine bookings past their hold: confirmed when the payment came in meanwhile
     * (webhook), otherwise cancelled so the rooms go back on sale. Returns [confirmed, cancelled].
     *
     * @return array{0: int, 1: int}
     */
    public function expireHolds(): array
    {
        $confirmed = 0;
        $cancelled = 0;
        Reservation::acrossProperties()->where('status', 'pending')->whereNotNull('hold_expires_at')->where('hold_expires_at', '<', now())
            ->orderBy('id')->limit(500)->get()
            ->each(function (Reservation $r) use (&$confirmed, &$cancelled) {
                $after = $this->confirmIfPaid($r);
                if ($after->status === 'confirmed') {
                    $confirmed++;

                    return;
                }
                $property = Property::query()->findOrFail($r->property_id);
                InProperty::run($property, fn () => $this->reservations->cancel($r->fresh(), __('booking.hold_expired'), null, true));
                $cancelled++;
            });

        return [$confirmed, $cancelled];
    }

    /** Amount the guest pays online now: full stay, a percentage, or the first nights. */
    public function amountDue(Reservation $reservation, RatePlan $plan): string
    {
        return $this->dueFor($plan, (string) $reservation->grand_total, (int) $reservation->nights);
    }

    public function dueFor(RatePlan $plan, string $total, int $nights): string
    {
        $value = (string) ($plan->deposit_value ?? '0');

        return Money::round(match ($plan->payment_type) {
            'prepay_full' => $total,
            'deposit_percent' => Money::min($total, Money::percent($total, $value)),
            'deposit_nights' => Money::min($total, Money::mul(Money::div($total, (string) max(1, $nights)), (string) max(1, (int) $value))),
            default => '0',
        });
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function dates(Property $property, array $q): array
    {
        try {
            $in = CarbonImmutable::createFromFormat('!Y-m-d', (string) ($q['check_in'] ?? ''));
            $out = CarbonImmutable::createFromFormat('!Y-m-d', (string) ($q['check_out'] ?? ''));
        } catch (\Throwable) {
            $in = $out = false;
        }
        if (! $in || ! $out) {
            throw ValidationException::withMessages(['check_in' => __('booking.errors.dates')]);
        }
        $today = $this->today($property);
        if ($in->lessThan($today)) {
            throw ValidationException::withMessages(['check_in' => __('booking.errors.past')]);
        }
        if ($in->greaterThan($today->addDays((int) config('ozepms.booking_engine.max_days_ahead')))) {
            throw ValidationException::withMessages(['check_in' => __('booking.errors.too_far')]);
        }
        $nights = (int) $in->diffInDays($out, false);
        if ($nights < 1) {
            throw ValidationException::withMessages(['check_out' => __('booking.errors.dates')]);
        }
        if ($nights > (int) config('ozepms.booking_engine.max_nights')) {
            throw ValidationException::withMessages(['check_out' => __('booking.errors.too_long', ['max' => config('ozepms.booking_engine.max_nights')])]);
        }

        return [$in, $out];
    }

    private function runSearch(Property $property, CarbonImmutable $in, CarbonImmutable $out, int $adults, int $children, int $infants, int $rooms, ?string $promo): array
    {
        $result = $this->availability->search($property, $in, $out, $adults, $children, $infants, 'booking_engine');
        $roomTypeIds = array_column($result->roomTypes, 'room_type_id');
        $types = RoomType::query()->whereIn('id', $roomTypeIds)->get()->keyBy('id');
        $images = RoomTypeImage::query()->whereIn('room_type_id', $roomTypeIds)->orderBy('sort_order')->get()->groupBy('room_type_id');
        $amenities = DB::table('room_type_amenities as rta')->join('amenities as a', 'a.id', '=', 'rta.amenity_id')
            ->whereIn('rta.room_type_id', $roomTypeIds)->where('a.is_active', 1)->orderBy('a.name')->get(['rta.room_type_id', 'a.name', 'a.label_key', 'a.icon'])->groupBy('room_type_id');
        $plans = RatePlan::query()->with(['mealPlan', 'cancellationPolicy.rules'])->whereIn('id', collect($result->roomTypes)->flatMap(fn ($rt) => array_column($rt['products'], 'rate_plan_id'))->unique())->get()->keyBy('id');
        $context = $this->reservations->offerContext($property, ['channel' => 'booking_engine', 'promo_code' => $promo,
            'source_id' => DB::table('booking_sources')->whereNull('property_id')->where('code', 'booking_engine')->value('id')], null);
        $childAges = [...array_fill(0, $children, 8), ...array_fill(0, $infants, 0)];
        $promoState = null;
        $list = [];

        foreach ($result->roomTypes as $rt) {
            $type = $types[$rt['room_type_id']] ?? null;
            if ($type === null) {
                continue;
            }
            $rates = [];
            foreach ($rt['products'] as $p) {
                if (! $p['sellable'] || $p['total'] === null || $rt['available_units'] < $rooms) {
                    continue;
                }
                $product = Product::query()->find($p['product_id']);
                $plan = $plans[$p['rate_plan_id']] ?? null;
                if ($product === null || $plan === null) {
                    continue;
                }
                $product->setRelation('ratePlan', $plan);
                $applied = null;
                $priced = $this->pricer->price($property, [0 => [
                    'product' => $product, 'check_in' => $in, 'check_out' => $out, 'adults' => $adults, 'children' => $children,
                    'infants' => $infants, 'child_ages' => $childAges, 'rate' => null,
                ]], $context, $applied)[0];
                // The crossed-out price must compare like with like: the total with taxes, without offers.
                $before = Money::isPositive($priced['discount_total']) ? $this->pricer->price($property, [0 => [
                    'product' => $product, 'check_in' => $in, 'check_out' => $out, 'adults' => $adults, 'children' => $children,
                    'infants' => $infants, 'child_ages' => $childAges, 'rate' => null,
                ]])[0]['grand_total'] : $priced['grand_total'];
                if ($applied->promo !== null && ($promoState === null || $applied->promo['status'] === 'applied')) {
                    $promoState = $applied->promo;
                }
                $mul = fn (string $v) => Money::round(Money::mul($v, (string) $rooms));
                $rates[] = [
                    'rate_plan_id' => $plan->public_id, 'name' => $plan->name, 'description' => $plan->description,
                    'meal_plan' => $plan->mealPlan?->label(), 'breakfast' => (bool) $plan->mealPlan?->includes_breakfast,
                    'refundable' => (bool) ($plan->cancellationPolicy?->is_refundable ?? true),
                    'policy' => $this->policyText($plan), 'payment_type' => $plan->payment_type,
                    'price_before' => $mul(Money::add($priced['room_total'], $priced['discount_total'])),
                    'room_total' => $mul($priced['room_total']), 'discount' => $mul($priced['discount_total']),
                    'taxes' => $mul($priced['tax_total']), 'grand_total' => $mul($priced['grand_total']), 'grand_before' => $mul($before),
                    'per_night' => Money::round(Money::div($priced['room_total'], (string) max(1, $result->nights))),
                    'pay_now' => $this->dueFor($plan, $mul($priced['grand_total']), $result->nights),
                    'tax_lines' => array_values(array_map(fn ($t) => [
                        'name' => $t['name'], 'amount' => $mul($t['amount']),
                        'rate' => $t['calc_type'] === 'percent' ? rtrim(rtrim(Money::normalize((string) $t['rate']), '0'), '.') : null,
                    ], array_filter($priced['tax_lines'], fn ($t) => Money::isPositive($t['amount'])))),
                    'policy_lines' => $this->policyLines($plan, (string) $property->currency_code),
                    'offers' => array_values(array_map(fn ($o) => ['name' => $o['name'], 'promo_code' => $o['promo_code']], $applied->applied)),
                ];
            }
            if ($rates === []) {
                continue;
            }
            usort($rates, fn ($a, $b) => (float) $a['grand_total'] <=> (float) $b['grand_total']);
            $list[] = [
                'id' => $type->public_id, 'name' => $type->name, 'description' => $type->description,
                'max_adults' => (int) $type->max_adults, 'max_children' => (int) $type->max_children, 'max_occupancy' => (int) $type->max_occupancy,
                'size' => $type->size_value ? rtrim(rtrim((string) $type->size_value, '0'), '.').' '.$type->size_unit : null,
                'view' => $type->view_label, 'smoking' => $type->smoking_policy,
                'images' => ($images[$type->id] ?? collect())->map(fn (RoomTypeImage $i) => $i->url())->values()->all(),
                'amenities' => ($amenities[$type->id] ?? collect())->map(fn ($a) => ['name' => $a->label_key && __($a->label_key) !== $a->label_key ? __($a->label_key) : $a->name, 'icon' => $a->icon])->values()->all(),
                'left' => (int) $rt['available_units'], 'rates' => $rates, 'from' => $rates[0]['grand_total'],
            ];
        }
        usort($list, fn ($a, $b) => (float) $a['from'] <=> (float) $b['from']);

        return [
            'check_in' => $result->checkIn, 'check_out' => $result->checkOut, 'nights' => $result->nights,
            'adults' => $adults, 'children' => $children, 'infants' => $infants, 'rooms' => $rooms, 'currency' => (string) $property->currency_code,
            'promo' => $promo === null ? null : [
                'code' => $promo, 'status' => $promoState['status'] ?? 'unknown',
                'message' => ($promoState['status'] ?? null) === 'applied' ? __('offers.applied.promo_applied', ['code' => $promo]) : __($promoState['reason'] ?? 'offers.reasons.unknown_code'),
            ],
            'room_types' => $list,
        ];
    }

    private function policyText(RatePlan $plan): string
    {
        $policy = $plan->cancellationPolicy;
        if ($policy === null) {
            return '';
        }
        if (! $policy->is_refundable) {
            return __('booking.policy.non_refundable');
        }
        $free = $policy->rules->where('applies_to', 'cancellation')->sortByDesc('hours_before_arrival')->first();

        return $free ? __('booking.policy.free_until', ['hours' => (int) $free->hours_before_arrival]) : __('booking.policy.free');
    }

    /**
     * Every cancellation and no-show charge of the rate plan in plain words, shown before booking.
     *
     * @return list<string>
     */
    private function policyLines(RatePlan $plan, string $currency): array
    {
        $policy = $plan->cancellationPolicy;
        if ($policy === null) {
            return [];
        }
        $lines = [];
        $cancel = $policy->rules->where('applies_to', 'cancellation')->sortByDesc('hours_before_arrival')->values();
        if (! $policy->is_refundable) {
            $lines[] = __('booking.policy.non_refundable_text');
        } elseif ($cancel->isNotEmpty()) {
            $lines[] = __('booking.policy.free_until', ['hours' => (int) $cancel[0]->hours_before_arrival]);
        } else {
            $lines[] = __('booking.policy.free');
        }
        foreach ($cancel as $rule) {
            if ($rule->charge_type === 'none' || (! $policy->is_refundable && $rule->charge_type === 'full')) {
                continue;
            }
            $lines[] = __('booking.policy.cancel_charge', ['hours' => (int) $rule->hours_before_arrival, 'charge' => $this->chargeText($rule, $currency)]);
        }
        foreach ($policy->rules->where('applies_to', 'no_show') as $rule) {
            $lines[] = __('booking.policy.no_show', ['charge' => $this->chargeText($rule, $currency)]);
        }

        return $lines;
    }

    private function chargeText(object $rule, string $currency): string
    {
        $value = (string) ($rule->charge_value ?? '0');

        return match ($rule->charge_type) {
            'first_night' => __('booking.policy.charges.first_night'),
            'nights' => __('booking.policy.charges.nights', ['count' => (int) $value]),
            'percent' => __('booking.policy.charges.percent', ['value' => rtrim(rtrim(Money::normalize($value), '0'), '.')]),
            'fixed' => __('booking.policy.charges.fixed', ['amount' => Money::display($value, $currency)]),
            'full' => __('booking.policy.charges.full'),
            default => __('booking.policy.charges.none'),
        };
    }
}
