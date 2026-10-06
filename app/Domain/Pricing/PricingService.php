<?php

namespace App\Domain\Pricing;

use App\Domain\Inventory\StayDates;
use App\Domain\Pricing\Exceptions\NoRateException;
use App\Domain\Rates\DerivedPrice;
use App\Domain\Rates\DerivedPricingGuard;
use App\Models\Product;
use App\Models\Property;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Room price for a stay, before offers and taxes. The one place prices are calculated
 * (PMS reservations, booking engine, channel manager, calendar previews).
 *
 * Price of one night for product P, A adults and children with ages C:
 *   manual P   base  = ari_daily.price
 *              price = ari_daily_occupancy[A] if set, else base + adult rules(A)
 *                      + child rules(C)
 *   derived P  base  = parent base ± adjustment (DerivedPrice; per-person uses the base adults)
 *              price = with own occupancy rules: base + own adult rules + own child rules
 *                      without:                  parent price for the same guests ± adjustment
 *                                                (per-person adjustments use A)
 * Occupancy rules (product_occupancy_rules), each fixed amount or percent of the night's base:
 *   adult n < base adults   applies when exactly n adults stay (n = 1: single occupancy)
 *   adult n > base adults   applies to every adult from the n-th on (extra adult)
 *   child / infant n        applies to every child from the n-th on, counted within the age band
 *                           of the rule (or across all children for a rule without band)
 * Children are matched to the property's age bands (infant band → infant rules); older children
 * are counted first. Night prices are never below zero and are rounded to the currency's units.
 */
class PricingService
{
    /**
     * Nights already priced, per data set (products are identified by id within one data set).
     *
     * @var \WeakMap<PricingData, \ArrayObject<string, array{base: string, price: string}|null>>
     */
    private \WeakMap $memo;

    /** @var \WeakMap<Product, array<string, mixed>> occupancy rules indexed per product object */
    private \WeakMap $compiled;

    public function __construct()
    {
        $this->memo = new \WeakMap;
        $this->compiled = new \WeakMap;
    }

    /**
     * Nightly breakdown and room total for one room of $product.
     *
     * @param  list<int>  $childAges
     *
     * @throws NoRateException when a night has no price
     */
    public function quote(Product $product, CarbonImmutable $checkIn, CarbonImmutable $checkOut, int $adults, array $childAges): Quote
    {
        $product = $this->loadChain($product);
        $chain = [];
        for ($p = $product, $i = 0; $p !== null && $i <= DerivedPricingGuard::MAX_DEPTH + 1; $p = $p->isDerived() ? $p->parent : null, $i++) {
            $chain[] = (int) $p->id;
        }
        $currency = (string) Property::query()->whereKey($product->property_id)->value('currency_code');
        $data = PricingData::load((int) $product->property_id, $chain, $checkIn, $checkOut->subDay(), $currency);

        $quote = $this->quoteWith($product, $checkIn, $checkOut, $adults, $childAges, $data, $missing);
        if ($quote === null) {
            throw new NoRateException((int) $product->id, $missing);
        }

        return $quote;
    }

    /**
     * Same as quote() with rows already loaded (availability search). Null when a night has no
     * price; $missing receives those dates. $product needs roomType, occupancyRules and (for derived
     * products) parent… loaded — see loadChain().
     *
     * @param  list<int>  $childAges
     * @param  list<string>|null  $missing
     */
    public function quoteWith(Product $product, CarbonImmutable $checkIn, CarbonImmutable $checkOut, int $adults, array $childAges, PricingData $data, ?array &$missing = null): ?Quote
    {
        $missing = [];
        $nights = [];
        $total = '0';
        $kids = $this->classifyChildren($childAges, $data->bands);

        foreach (StayDates::nights($checkIn, $checkOut) as $date) {
            $night = $this->night($product, $date, $adults, $kids, $data, 0);
            if ($night === null) {
                $missing[] = $date;

                continue;
            }
            $base = Money::round($night['base'], $data->places);
            $price = Money::round(Money::max($night['price'], '0'), $data->places);
            $nights[] = [
                'date' => $date,
                'base_price' => $base,
                'occupancy_adjust' => Money::round(Money::sub($price, $base), $data->places),
                'price' => $price,
            ];
            $total = Money::add($total, $price);
        }
        if ($missing !== [] || $nights === []) {
            return null;
        }

        return new Quote(
            productId: (int) $product->id,
            roomTypeId: (int) $product->room_type_id,
            ratePlanId: (int) $product->rate_plan_id,
            checkIn: $checkIn,
            checkOut: $checkOut,
            adults: $adults,
            childAges: array_values($childAges),
            nights: $nights,
            roomTotal: Money::round($total, $data->places),
            currency: $data->currency,
        );
    }

    /** Loads what pricing needs: room type, occupancy rules and the parent chain. */
    public function loadChain(Product $product): Product
    {
        $product->loadMissing(['roomType', 'occupancyRules']);
        $current = $product;
        for ($i = 0; $current->isDerived() && $i <= DerivedPricingGuard::MAX_DEPTH; $i++) {
            $current->loadMissing(['parent.roomType', 'parent.occupancyRules']);
            $current = $current->parent;
            if ($current === null) {
                break;
            }
        }

        return $product;
    }

    /**
     * @param  list<array{age: int, type: string, band: ?int}>  $kids
     * @return array{base: string, price: string}|null
     */
    private function night(Product $product, string $date, int $adults, array $kids, PricingData $data, int $depth): ?array
    {
        // A parent's night is the same for every derived product of a search: compute it once.
        $key = $product->id.'|'.$date.'|'.$adults.'|'.implode(',', array_column($kids, 'age'));
        $memo = $this->memo[$data] ??= new \ArrayObject;
        if ($memo->offsetExists($key)) {
            return $memo[$key];
        }

        return $memo[$key] = $this->computeNight($product, $date, $adults, $kids, $data, $depth);
    }

    /** @return array{base: string, price: string}|null */
    private function computeNight(Product $product, string $date, int $adults, array $kids, PricingData $data, int $depth): ?array
    {
        $rules = $this->rules($product);
        $baseAdults = max(1, (int) ($product->roomType?->base_adults ?? 1));

        if (! $product->isDerived()) {
            $base = $data->ari[(int) $product->id][$date]['price'] ?? null;
            if ($base === null) {
                return null;
            }
            $base = (string) $base;
            $adultPart = $data->occupancy[(int) $product->id][$date][$adults]
                ?? Money::add($base, $this->adultAdjust($rules, $base, $adults, $baseAdults));

            return ['base' => $base, 'price' => Money::add($adultPart, $this->childAdjust($rules, $base, $kids))];
        }

        $parent = $product->parent;
        if ($parent === null || $depth > DerivedPricingGuard::MAX_DEPTH) {
            return null;
        }
        $parentNight = $this->night($parent, $date, $adults, $kids, $data, $depth + 1);
        if ($parentNight === null) {
            return null;
        }
        $type = (string) $product->adjust_type;
        $value = (string) $product->adjust_value;
        $base = DerivedPrice::apply($parentNight['base'], $type, $value, $baseAdults, Money::SCALE);

        if ($rules['any']) {
            $price = Money::add($base, $this->adultAdjust($rules, $base, $adults, $baseAdults), $this->childAdjust($rules, $base, $kids));
        } else {
            $price = DerivedPrice::apply($parentNight['price'], $type, $value, max(1, $adults), Money::SCALE);
        }

        return ['base' => $base, 'price' => $price];
    }

    /**
     * Occupancy rules of a product, indexed once:
     *   adult[n]                   rule for n adults
     *   kids[type][band|'-'][n]    rule for the n-th child / infant (band '-' = any band)
     *
     * @return array{any: bool, adult: array<int, array{0: string, 1: string}>, kids: array<string, array<string, array<int, array{0: string, 1: string}>>>}
     */
    private function rules(Product $product): array
    {
        if (isset($this->compiled[$product])) {
            return $this->compiled[$product];
        }
        $out = ['any' => false, 'adult' => [], 'kids' => []];
        foreach ($product->occupancyRules ?? [] as $rule) {
            $spec = [(string) $rule->adjust_type, (string) $rule->adjust_value];
            $n = (int) $rule->guest_count;
            $out['any'] = true;
            if ($rule->guest_type === 'adult') {
                $out['adult'][$n] = $spec;
            } else {
                $out['kids'][$rule->guest_type][$rule->age_band_id === null ? '-' : (string) $rule->age_band_id][$n] = $spec;
            }
        }
        ksort($out['adult']);
        foreach ($out['kids'] as &$byBand) {
            foreach ($byBand as &$byCount) {
                ksort($byCount);
            }
        }

        $this->compiled[$product] = $out;

        return $out;
    }

    private function adultAdjust(array $rules, string $base, int $adults, int $baseAdults): string
    {
        if ($rules['adult'] === [] || $adults === $baseAdults) {
            return '0';
        }
        if ($adults < $baseAdults) {
            return isset($rules['adult'][$adults]) ? $this->amount($rules['adult'][$adults], $base) : '0';
        }

        $extra = '0';
        for ($k = $baseAdults + 1; $k <= $adults; $k++) {
            $spec = $this->fromNth($rules['adult'], $k, $baseAdults + 1);
            if ($spec !== null) {
                $extra = Money::add($extra, $this->amount($spec, $base));
            }
        }

        return $extra;
    }

    /** @param  list<array{age: int, type: string, band: ?int}>  $kids */
    private function childAdjust(array $rules, string $base, array $kids): string
    {
        if ($kids === [] || $rules['kids'] === []) {
            return '0';
        }
        $total = '0';
        $perBand = [];
        $perType = [];
        foreach ($kids as $kid) {
            $band = $kid['band'] === null ? '-' : (string) $kid['band'];
            $nBand = $perBand[$kid['type'].':'.$band] = ($perBand[$kid['type'].':'.$band] ?? 0) + 1;
            $nType = $perType[$kid['type']] = ($perType[$kid['type']] ?? 0) + 1;
            $byBand = $rules['kids'][$kid['type']] ?? [];

            $spec = $band !== '-' && isset($byBand[$band]) ? $this->fromNth($byBand[$band], $nBand) : null;
            $spec ??= isset($byBand['-']) ? $this->fromNth($byBand['-'], $nType) : null;
            if ($spec !== null) {
                $total = Money::add($total, $this->amount($spec, $base));
            }
        }

        return $total;
    }

    /**
     * The rule with the highest count ≤ $n (and ≥ $min): a rule for the n-th guest applies to every
     * guest from the n-th on. $byCount is sorted by count.
     *
     * @param  array<int, array{0: string, 1: string}>  $byCount
     */
    private function fromNth(array $byCount, int $n, int $min = 1): ?array
    {
        $found = null;
        foreach ($byCount as $count => $spec) {
            if ($count > $n) {
                break;
            }
            if ($count >= $min) {
                $found = $spec;
            }
        }

        return $found;
    }

    /** @param  array{0: string, 1: string}  $spec  [adjust type, value] */
    private function amount(array $spec, string $base): string
    {
        return $spec[0] === 'percent' ? Money::percent($base, $spec[1]) : Money::normalize($spec[1]);
    }

    /**
     * @param  list<int>  $ages
     * @param  list<array{id: int, code: string, min_age: int, max_age: int}>  $bands
     * @return list<array{age: int, type: string, band: ?int}>
     */
    private function classifyChildren(array $ages, array $bands): array
    {
        $ages = array_map('intval', $ages);
        rsort($ages);
        $out = [];
        foreach ($ages as $age) {
            $band = null;
            foreach ($bands as $b) {
                if ($age >= $b['min_age'] && $age <= $b['max_age']) {
                    $band = $b;
                    break;
                }
            }
            $out[] = ['age' => $age, 'type' => ($band['code'] ?? null) === 'infant' ? 'infant' : 'child', 'band' => $band['id'] ?? null];
        }

        return $out;
    }
}
