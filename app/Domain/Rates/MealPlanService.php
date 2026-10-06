<?php

namespace App\Domain\Rates;

use App\Domain\Audit\AuditLogger;
use App\Infrastructure\Database\Tx;
use App\Models\MealPlan;
use App\Support\PropertyContext;
use Illuminate\Validation\ValidationException;

/**
 * Custom meal plans of a property ("Breakfast + Spa", "Lunch Box" …) next to the standard
 * ones. A custom code may not reuse a standard code, so channel mapping stays unambiguous.
 */
class MealPlanService
{
    public function __construct(
        private readonly PropertyContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /** @param  array<string, mixed>  $data  code, name, includes_breakfast, includes_lunch, includes_dinner, is_all_inclusive */
    public function createCustom(array $data): MealPlan
    {
        return Tx::run(function () use ($data) {
            $propertyId = $this->context->id();
            $code = strtoupper(trim((string) $data['code']));
            $name = trim((string) $data['name']);

            $taken = MealPlan::query()->visibleToProperty($propertyId)
                ->where(fn ($q) => $q->where('code', $code)->orWhere('name', $name))
                ->lockForUpdate()->first();
            if ($taken) {
                $field = $taken->code === $code ? 'code' : 'name';
                throw ValidationException::withMessages([$field => __('rates.meal_plan_custom.taken')]);
            }

            $allInclusive = (bool) ($data['is_all_inclusive'] ?? false);
            $plan = MealPlan::query()->create([
                'property_id' => $propertyId,
                'code' => $code,
                'name' => $name,
                'includes_breakfast' => $allInclusive || (bool) ($data['includes_breakfast'] ?? false),
                'includes_lunch' => $allInclusive || (bool) ($data['includes_lunch'] ?? false),
                'includes_dinner' => $allInclusive || (bool) ($data['includes_dinner'] ?? false),
                'is_all_inclusive' => $allInclusive,
                'is_active' => true,
            ]);
            $this->audit->log('meal_plan.created', $plan, ['after' => $plan->only(['code', 'name', 'includes_breakfast', 'includes_lunch', 'includes_dinner', 'is_all_inclusive'])], $propertyId);

            return $plan;
        });
    }
}
