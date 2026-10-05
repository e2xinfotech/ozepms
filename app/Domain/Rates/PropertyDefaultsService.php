<?php

namespace App\Domain\Rates;

use App\Domain\Accommodation\InProperty;
use App\Domain\Audit\AuditLogger;
use App\Domain\Rates\Reference\MealPlans;
use App\Domain\Tax\Reference\TaxReference;
use App\Models\CancellationPolicy;
use App\Models\Property;
use App\Models\RatePlan;

/**
 * Gives every new property a usable starting point: two cancellation policies,
 * the "Standard Rate – Room Only" plan (BAR) and, for India, the GST slabs.
 * Safe to run twice: existing codes are left alone.
 */
class PropertyDefaultsService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function seed(Property $property): void
    {
        InProperty::run($property, function () use ($property) {
            $flexible = $this->policy('FLEX', __('rates.defaults.flexible_name'), true, __('rates.defaults.flexible_text'), [
                ['applies_to' => 'cancellation', 'hours_before_arrival' => 24, 'charge_type' => 'first_night', 'charge_value' => null],
                ['applies_to' => 'no_show', 'hours_before_arrival' => 0, 'charge_type' => 'first_night', 'charge_value' => null],
            ]);
            $this->policy('NRF', __('rates.defaults.non_refundable_name'), false, __('rates.defaults.non_refundable_text'), [
                ['applies_to' => 'cancellation', 'hours_before_arrival' => 8760, 'charge_type' => 'full', 'charge_value' => null],
                ['applies_to' => 'no_show', 'hours_before_arrival' => 0, 'charge_type' => 'full', 'charge_value' => null],
            ]);

            if (! RatePlan::query()->withTrashed()->where('code', 'BAR')->exists()) {
                $plan = RatePlan::query()->create([
                    'code' => 'BAR',
                    'name' => __('rates.defaults.bar_name'),
                    'description' => __('rates.defaults.bar_text'),
                    'meal_plan_id' => MealPlans::ensure('RO')->id,
                    'cancellation_policy_id' => $flexible->id,
                    'payment_type' => 'pay_at_property',
                    'default_min_los' => 1,
                    'is_default' => true,
                ]);
                $this->audit->log('rate_plan.created', $plan, ['after' => ['code' => 'BAR', 'source' => 'property_defaults']], $property->id);
            }

            if ($property->country_iso2 === 'IN') {
                $copied = TaxReference::copyTemplatesToProperty($property->id, 'IN');
                if ($copied > 0) {
                    $this->audit->log('tax_rule.defaults_copied', $property, ['after' => ['country' => 'IN', 'rules' => $copied]], $property->id);
                }
            }
        });
    }

    /** @param  list<array<string, mixed>>  $rules */
    private function policy(string $code, string $name, bool $refundable, string $text, array $rules): CancellationPolicy
    {
        $existing = CancellationPolicy::query()->where('code', $code)->first();
        if ($existing) {
            return $existing;
        }

        $policy = CancellationPolicy::query()->create([
            'code' => $code, 'name' => $name, 'is_refundable' => $refundable, 'description' => $text, 'is_active' => true,
        ]);
        $policy->rules()->createMany($rules);

        return $policy;
    }
}
