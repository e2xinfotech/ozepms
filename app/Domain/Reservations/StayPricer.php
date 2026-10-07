<?php

namespace App\Domain\Reservations;

use App\Domain\Inventory\StayDates;
use App\Domain\Offers\OfferContext;
use App\Domain\Offers\OfferResult;
use App\Domain\Offers\OfferService;
use App\Domain\Pricing\Exceptions\NoRateException;
use App\Domain\Pricing\PricingService;
use App\Domain\Tax\TaxService;
use App\Models\Product;
use App\Models\Property;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Prices the rooms of a booking night by night (PricingService) and adds taxes (TaxService)
 * with one tax call for every night of every room, so per-booking fees are charged once.
 *
 * Room spec: ['product' => Product, 'check_in' => CarbonImmutable, 'check_out' => CarbonImmutable,
 *   'adults' => int, 'child_ages' => list<int> (children and infants), 'rate' => ?string (manual
 *   price for every night), 'nightly' => ?array<date, price> (channel price per night), 'keep' => array<date, price> (nights whose price is kept, modify)].
 *
 * Result per room: ['nights' => [date => [base_price, occupancy_adjust, discount, price, net_price,
 *   tax_amount]], 'room_total' (taxable), 'discount_total' (offers), 'tax_total', 'grand_total', 'components' => [code => amount]].
 * Amounts are decimal strings rounded to the currency.
 *
 * With an OfferContext, offers (OfferService) lower the night prices before tax. Rooms with a manual
 * price get no offer; kept nights (modify) keep their price and discount and are never re-discounted,
 * unless the room spec has 'reoffer' (the promo code was changed): then their offers are worked out again.
 */
class StayPricer
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly TaxService $taxes,
        private readonly OfferService $offers,
    ) {}

    /**
     * @param  array<int|string, array<string, mixed>>  $rooms  keyed like the request (rooms.N)
     * @return array<int|string, array<string, mixed>>
     */
    public function price(Property $property, array $rooms, ?OfferContext $context = null, ?OfferResult &$applied = null): array
    {
        $currency = (string) $property->currency_code;
        $places = Money::minorUnits($currency);
        $lines = [];
        $index = [];
        $result = [];
        $nightsOf = [];
        foreach ($rooms as $key => $spec) {
            $nightsOf[$key] = $this->nightPrices($spec, (string) $key, $places);
        }

        $applied = OfferResult::none();
        if ($context !== null) {
            $offerRooms = [];
            foreach ($rooms as $key => $spec) {
                if (($spec['rate'] ?? null) !== null || ! empty($spec['nightly']) || $nightsOf[$key] === []) {
                    continue;
                }
                $offerRooms[$key] = [
                    'room_type_id' => (int) $spec['product']->room_type_id, 'rate_plan_id' => (int) $spec['product']->rate_plan_id,
                    'check_in' => $spec['check_in'], 'check_out' => $spec['check_out'], 'adults' => (int) $spec['adults'],
                    // Offers work on the price before any discount; kept nights are fixed.
                    'nights' => array_map(fn ($n) => Money::add($n['price'], $n['discount']), $nightsOf[$key]),
                    'fixed' => ! empty($spec['reoffer']) ? [] : array_keys($spec['keep'] ?? []),
                ];
            }
            $applied = $this->offers->evaluate($property, $context, $offerRooms);
            foreach ($applied->rooms as $key => $room) {
                foreach ($room['nights'] as $date => $discount) {
                    if (Money::isPositive($discount) && (! empty($rooms[$key]['reoffer']) || ! isset($rooms[$key]['keep'][$date]))) {
                        $n = &$nightsOf[$key][$date];
                        $n['discount'] = Money::round($discount, $places);
                        $n['price'] = Money::round(Money::sub(Money::add($n['base_price'], $n['occupancy_adjust']), $discount), $places);
                        unset($n);
                    }
                }
            }
        }

        foreach ($rooms as $key => $spec) {
            $nights = $nightsOf[$key];
            $persons = (int) $spec['adults'] + count($spec['child_ages'] ?? []);
            $result[$key] = ['nights' => $nights];
            foreach ($nights as $date => $n) {
                $index[] = [$key, $date];
                $lines[] = [
                    'category' => 'accommodation', 'amount' => $n['price'], 'date' => $date, 'unit_night_tariff' => $n['price'],
                    'room_type_id' => (int) $spec['product']->room_type_id, 'rate_plan_id' => (int) $spec['product']->rate_plan_id,
                    'nights' => 1, 'persons' => max(1, $persons),
                ];
            }
        }

        $breakdown = $lines === [] ? null : $this->taxes->calculate($property, $lines);
        foreach ($index as $i => [$key, $date]) {
            $line = $breakdown->lines[$i];
            $result[$key]['nights'][$date]['net_price'] = $line['taxable'];
            $result[$key]['nights'][$date]['tax_amount'] = $line['tax_total'];
            foreach ($line['components'] as $c) {
                $result[$key]['components'][$c['component']] = Money::round(Money::add($result[$key]['components'][$c['component']] ?? '0', $c['amount']), $places);
                // Named lines for guests (booking engine): "VAT 5 %", "Tourism Dirham Fee".
                $result[$key]['tax_lines'][$c['component']] ??= ['name' => $c['name'], 'rate' => $c['rate'], 'calc_type' => $c['calc_type'], 'amount' => '0'];
                $result[$key]['tax_lines'][$c['component']]['amount'] = Money::round(Money::add($result[$key]['tax_lines'][$c['component']]['amount'], $c['amount']), $places);
            }
        }

        foreach ($result as $key => $room) {
            $net = Money::sum(array_column($room['nights'], 'net_price'));
            $tax = Money::sum(array_column($room['nights'], 'tax_amount'));
            $result[$key]['room_total'] = Money::round($net, $places);
            $result[$key]['discount_total'] = Money::round(Money::sum(array_column($room['nights'], 'discount')), $places);
            $result[$key]['tax_total'] = Money::round($tax, $places);
            $result[$key]['grand_total'] = Money::round(Money::add($net, $tax), $places);
            $result[$key]['components'] ??= [];
            $result[$key]['tax_lines'] ??= [];
        }

        return $result;
    }

    /** @return array<string, array<string, string>> */
    private function nightPrices(array $spec, string $key, int $places): array
    {
        /** @var Product $product */
        $product = $spec['product'];
        $dates = StayDates::nights($spec['check_in'], $spec['check_out']);
        $keep = $spec['keep'] ?? [];
        $rate = $spec['rate'] ?? null;
        $out = [];

        // Channel imports: the channel's own price per night (a missing night falls back to 'rate' or the first night).
        if (! empty($spec['nightly'])) {
            $fallback = Money::round((string) ($rate ?? reset($spec['nightly'])), $places);
            foreach ($dates as $date) {
                $p = isset($spec['nightly'][$date]) ? Money::round((string) $spec['nightly'][$date], $places) : $fallback;
                $out[$date] = ['base_price' => $p, 'occupancy_adjust' => '0.00', 'discount' => '0.00', 'price' => $p];
            }

            return $out;
        }

        if ($rate !== null) {
            $rate = Money::round((string) $rate, $places);
            foreach ($dates as $date) {
                $out[$date] = ['base_price' => $rate, 'occupancy_adjust' => '0.00', 'discount' => '0.00', 'price' => $rate];
            }

            return $out;
        }

        // Quote only the runs of nights that are not kept (an extended stay keeps its priced nights).
        $runs = [];
        $start = null;
        foreach ($dates as $i => $date) {
            $missing = ! isset($keep[$date]);
            if ($missing && $start === null) {
                $start = $date;
            }
            $last = $i === count($dates) - 1;
            if ($start !== null && (! $missing || $last)) {
                $end = $missing ? CarbonImmutable::parse($date)->addDay() : CarbonImmutable::parse($date);
                $runs[] = [CarbonImmutable::parse($start), $end];
                $start = null;
            }
        }

        $quoted = [];
        foreach ($runs as [$from, $to]) {
            try {
                $quote = $this->pricing->quote($product, $from, $to, (int) $spec['adults'], array_values($spec['child_ages'] ?? []));
            } catch (NoRateException $e) {
                throw ValidationException::withMessages(["rooms.{$key}.rate_plan_id" => __('reservations.errors.no_rate', ['dates' => implode(', ', $e->dates)])]);
            }
            foreach ($quote->nights as $n) {
                $quoted[$n['date']] = $n;
            }
        }

        foreach ($dates as $date) {
            if (isset($keep[$date])) {
                $k = is_array($keep[$date]) ? $keep[$date] : ['price' => $keep[$date]];
                $price = Money::round((string) $k['price'], $places);
                $out[$date] = [
                    'base_price' => Money::round((string) ($k['base_price'] ?? $price), $places),
                    'occupancy_adjust' => Money::round((string) ($k['occupancy_adjust'] ?? '0'), $places),
                    'discount' => Money::round((string) ($k['discount'] ?? '0'), $places), 'price' => $price,
                ];

                continue;
            }
            $n = $quoted[$date];
            $out[$date] = [
                'base_price' => Money::round($n['base_price'], $places),
                'occupancy_adjust' => Money::round($n['occupancy_adjust'], $places),
                'discount' => '0.00',
                'price' => Money::round($n['price'], $places),
            ];
        }

        return $out;
    }
}
