<?php

namespace App\Domain\Pricing;

use App\Domain\Pricing\Exceptions\NoRateException;
use App\Domain\Rates\DerivedPrice;
use App\Domain\Rates\DerivedPricingGuard;
use App\Models\Product;
use App\Models\Property;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

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

        for ($d = $checkIn; $d->lessThan($checkOut); $d = $d->addDay()) {
            $date = $d->toDateString();
            $night = $this->night($product, $date, $adults, $kids, $data, 0);
            if ($night === null) {
                $missing[] = $date;

                continue;
            }
            $base = Money::forCurrency($night['base'], $data->currency);
            $price = Money::forCurrency(Money::max($night['price'], '0'), $data->currency);
            $nights[] = [
                'date' => $date,
                'base_price' => $base,
                'occupancy_adjust' => Money::forCurrency(Money::sub($price, $base), $data->currency),
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
            roomTotal: Money::forCurrency($total, $data->currency),
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
        $rules = $product->occupancyRules ?? new Collection;
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

        if ($rules->isNotEmpty()) {
            $price = Money::add($base, $this->adultAdjust($rules, $base, $adults, $baseAdults), $this->childAdjust($rules, $base, $kids));
        } else {
            $price = DerivedPrice::apply($parentNight['price'], $type, $value, max(1, $adults), Money::SCALE);
        }

        return ['base' => $base, 'price' => $price];
    }

    private function adultAdjust(Collection $rules, string $base, int $adults, int $baseAdults): string
    {
        $adultRules = $rules->where('guest_type', 'adult');
        if ($adultRules->isEmpty() || $adults === $baseAdults) {
            return '0';
        }
        if ($adults < $baseAdults) {
            $rule = $adultRules->first(fn ($r) => (int) $r->guest_count === $adults);

            return $rule ? $this->amount($rule, $base) : '0';
        }

        $extra = '0';
        for ($k = $baseAdults + 1; $k <= $adults; $k++) {
            $rule = $adultRules
                ->filter(fn ($r) => (int) $r->guest_count > $baseAdults && (int) $r->guest_count <= $k)
                ->sortByDesc(fn ($r) => (int) $r->guest_count)
                ->first();
            if ($rule) {
                $extra = Money::add($extra, $this->amount($rule, $base));
            }
        }

        return $extra;
    }

    /** @param  list<array{age: int, type: string, band: ?int}>  $kids */
    private function childAdjust(Collection $rules, string $base, array $kids): string
    {
        if ($kids === [] || $rules->isEmpty()) {
            return '0';
        }
        $total = '0';
        $perBand = [];
        $perType = [];
        foreach ($kids as $kid) {
            $nBand = $perBand[$kid['type'].':'.($kid['band'] ?? '-')] = ($perBand[$kid['type'].':'.($kid['band'] ?? '-')] ?? 0) + 1;
            $nType = $perType[$kid['type']] = ($perType[$kid['type']] ?? 0) + 1;
            $typeRules = $rules->where('guest_type', $kid['type']);

            $rule = $kid['band'] === null ? null : $typeRules
                ->filter(fn ($r) => (int) $r->age_band_id === $kid['band'] && (int) $r->guest_count <= $nBand)
                ->sortByDesc(fn ($r) => (int) $r->guest_count)->first();
            $rule ??= $typeRules
                ->filter(fn ($r) => $r->age_band_id === null && (int) $r->guest_count <= $nType)
                ->sortByDesc(fn ($r) => (int) $r->guest_count)->first();
            if ($rule) {
                $total = Money::add($total, $this->amount($rule, $base));
            }
        }

        return $total;
    }

    private function amount(object $rule, string $base): string
    {
        $value = (string) $rule->adjust_value;

        return $rule->adjust_type === 'percent' ? Money::percent($base, $value) : Money::normalize($value);
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
