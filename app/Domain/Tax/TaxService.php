<?php

namespace App\Domain\Tax;

use App\Models\Property;
use App\Models\State;
use App\Models\TaxRule;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Calculates taxes, service charges and fees for charge lines of one property.
 *
 * Rules: the property's own rules, or its country's templates when it has none.
 * Per line, applicable rules run in priority order. Each tax is linear in the net
 * base B (percent: r·B, fixed: c, compound percent: r·(B + earlier taxes)), so
 * inclusive prices are back-calculated exactly: B = (amount − Σc) / (1 + Σr).
 * Every component is rounded to the currency's minor units; for inclusive lines
 * the taxable amount absorbs the rounding so taxable + inclusive taxes = price.
 */
class TaxService
{
    public function __construct(private readonly TaxRuleMatcher $matcher = new TaxRuleMatcher) {}

    /**
     * @param  array<int, array<string, mixed>>  $lines  see TaxLine::from()
     */
    public function calculate(Property $property, array $lines, ?string $guestStateCode = null): TaxBreakdown
    {
        $currency = (string) $property->currency_code;
        $places = Money::minorUnits($currency);
        $rules = $this->rulesFor($property);
        $interstate = $this->isInterstate($property, $guestStateCode);
        $perBookingDone = [];

        $out = [];
        $taxableTotal = '0';
        $taxTotal = '0';

        foreach (array_values($lines) as $raw) {
            $line = TaxLine::from($raw);
            $applicable = $rules->filter(fn (TaxRule $rule) => $this->matcher->applies($rule, $line))->values();
            $result = $this->calculateLine($line, $applicable, $places, $interstate && ! $line->isAccommodation(), $perBookingDone);

            $taxableTotal = Money::add($taxableTotal, $result['taxable']);
            $taxTotal = Money::add($taxTotal, $result['tax_total']);
            $out[] = $result;
        }

        return new TaxBreakdown($out, Money::round($taxableTotal, $places), Money::round($taxTotal, $places), $currency);
    }

    /** Active rules for the property in calculation order. */
    public function rulesFor(Property $property): Collection
    {
        $hasOwn = TaxRule::query()->forProperty($property->id)->exists();
        $query = $hasOwn
            ? TaxRule::query()->forProperty($property->id)
            : TaxRule::query()->templatesFor((string) $property->country_iso2);

        return $query->where('is_active', true)
            ->with('scopes')
            ->orderBy('priority')->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, TaxRule>  $rules
     * @param  array<int, bool>  $perBookingDone  rule ids whose per-booking fee was already charged
     * @return array<string, mixed>
     */
    private function calculateLine(TaxLine $line, Collection $rules, int $places, bool $interstate, array &$perBookingDone): array
    {
        $sign = Money::isNegative($line->amount) ? '-1' : '1';

        // Linear coefficients of every applicable rule: tax = a·B + c.
        $terms = [];
        $sumA = '0';
        $sumC = '0';
        foreach ($rules as $rule) {
            if ($rule->calc_type === 'fixed_per_booking' && isset($perBookingDone[$rule->id])) {
                continue;
            }
            if ($rule->calc_type === 'percent') {
                $r = Money::div((string) $rule->rate, '100');
                $a = $rule->is_compound ? Money::mul($r, Money::add('1', $sumA)) : $r;
                $c = $rule->is_compound ? Money::mul($r, $sumC) : '0';
            } else {
                $a = '0';
                $c = Money::mul($this->fixedAmount($rule, $line), $sign);
            }
            if ($rule->calc_type === 'fixed_per_booking') {
                $perBookingDone[$rule->id] = true;
            }
            $terms[] = ['rule' => $rule, 'a' => $a, 'c' => $c];
            $sumA = Money::add($sumA, $a);
            $sumC = Money::add($sumC, $c);
        }

        $inclusiveA = '0';
        $inclusiveC = '0';
        foreach ($terms as $term) {
            if ($term['rule']->is_inclusive) {
                $inclusiveA = Money::add($inclusiveA, $term['a']);
                $inclusiveC = Money::add($inclusiveC, $term['c']);
            }
        }
        $base = Money::div(Money::sub($line->amount, $inclusiveC), Money::add('1', $inclusiveA));

        $components = [];
        $earlierExact = '0';
        $inclusiveRounded = '0';
        $taxTotal = '0';
        foreach ($terms as $term) {
            /** @var TaxRule $rule */
            $rule = $term['rule'];
            $ruleBase = $rule->calc_type === 'percent' && $rule->is_compound ? Money::add($base, $earlierExact) : $base;
            $exact = Money::add(Money::mul($term['a'], $base), $term['c']);

            foreach ($this->components($rule, $ruleBase, $exact, $places, $interstate) as $component) {
                $components[] = $component;
                $taxTotal = Money::add($taxTotal, $component['amount']);
                if ($rule->is_inclusive) {
                    $inclusiveRounded = Money::add($inclusiveRounded, $component['amount']);
                }
            }
            $earlierExact = Money::add($earlierExact, $exact);
        }

        $taxable = Money::round(Money::sub($line->amount, $inclusiveRounded), $places);
        $taxTotal = Money::round($taxTotal, $places);

        return [
            'amount' => Money::round($line->amount, $places),
            'taxable' => $taxable,
            'tax_total' => $taxTotal,
            'total' => Money::round(Money::add($taxable, $taxTotal), $places),
            'components' => $components,
        ];
    }

    private function fixedAmount(TaxRule $rule, TaxLine $line): string
    {
        $rate = (string) $rule->rate;

        return match ($rule->calc_type) {
            'fixed_per_night' => Money::mul($rate, $line->nights),
            'fixed_per_person_night' => Money::mul($rate, $line->nights * $line->persons),
            default => Money::normalize($rate), // fixed_per_stay, fixed_per_booking
        };
    }

    /**
     * Splits GST into CGST + SGST (same state) or IGST (inter-state); other taxes are one component.
     *
     * @return list<array<string, mixed>>
     */
    private function components(TaxRule $rule, string $ruleBase, string $exact, int $places, bool $interstate): array
    {
        $taxable = Money::round($ruleBase, $places);
        $make = fn (string $component, string $name, string $rate, string $amount) => [
            'tax_rule_id' => $rule->id,
            'code' => $rule->code,
            'component' => $component,
            'name' => $name,
            'kind' => $rule->kind,
            'tax_type' => $rule->tax_type,
            'calc_type' => $rule->calc_type,
            'rate' => Money::round($rate, 4),
            'taxable' => $taxable,
            'amount' => $amount,
            'inclusive' => (bool) $rule->is_inclusive,
        ];

        if ($rule->component_mode !== 'gst_split') {
            return [$make($this->componentCode($rule), (string) $rule->name, (string) $rule->rate, Money::round($exact, $places))];
        }

        if ($interstate) {
            return [$make('IGST', 'IGST', (string) $rule->rate, Money::round($exact, $places))];
        }

        $halfRate = Money::div((string) $rule->rate, '2');
        if ($rule->calc_type === 'percent') {
            $half = Money::round(Money::percent($ruleBase, $halfRate), $places);

            return [$make('CGST', 'CGST', $halfRate, $half), $make('SGST', 'SGST', $halfRate, $half)];
        }

        $total = Money::round($exact, $places);
        $first = Money::round(Money::div($total, '2'), $places);

        return [
            $make('CGST', 'CGST', $halfRate, $first),
            $make('SGST', 'SGST', $halfRate, Money::round(Money::sub($total, $first), $places)),
        ];
    }

    private function componentCode(TaxRule $rule): string
    {
        return strtoupper(substr((string) $rule->code, 0, 20));
    }

    /** Accommodation is always taxed at the property's location; other supplies may be inter-state. */
    private function isInterstate(Property $property, ?string $guestStateCode): bool
    {
        if ($guestStateCode === null || $guestStateCode === '' || ! $property->state_id) {
            return false;
        }

        $state = State::query()->find($property->state_id);
        if (! $state) {
            return false;
        }

        $guest = strtoupper(trim($guestStateCode));

        return ! in_array($guest, array_filter([strtoupper((string) $state->code), (string) $state->tax_region_code]), true);
    }
}
