<?php

namespace App\Domain\Tax;

use App\Models\TaxRule;
use App\Models\TaxRuleScope;
use App\Support\Money;

/** Decides whether a tax rule applies to a line: bucket, effective dates, scope and slab. */
final class TaxRuleMatcher
{
    public function applies(TaxRule $rule, TaxLine $line): bool
    {
        return in_array($line->bucket(), $rule->applyToList(), true)
            && $this->isEffective($rule, $line->date)
            && $this->inScope($rule, $line)
            && $this->inSlab($rule, $line);
    }

    public function isEffective(TaxRule $rule, string $date): bool
    {
        if ($rule->effective_from->toDateString() > $date) {
            return false;
        }

        return $rule->effective_to === null || $rule->effective_to->toDateString() >= $date;
    }

    /** No scope rows means "everything"; otherwise any one matching scope row is enough. */
    public function inScope(TaxRule $rule, TaxLine $line): bool
    {
        if ($rule->scopes->isEmpty()) {
            return true;
        }

        return $rule->scopes->contains(fn (TaxRuleScope $scope) => ($scope->room_type_id === null || (int) $scope->room_type_id === $line->roomTypeId)
            && ($scope->rate_plan_id === null || (int) $scope->rate_plan_id === $line->ratePlanId));
    }

    /** Slab bounds are inclusive; a missing bound is open. */
    public function inSlab(TaxRule $rule, TaxLine $line): bool
    {
        if ($rule->slab_basis !== 'unit_night_tariff') {
            return true;
        }

        $tariff = Money::abs($line->unitNightTariff);
        if ($rule->slab_min !== null && Money::compare($tariff, (string) $rule->slab_min) < 0) {
            return false;
        }

        return $rule->slab_max === null || Money::compare($tariff, (string) $rule->slab_max) <= 0;
    }
}
