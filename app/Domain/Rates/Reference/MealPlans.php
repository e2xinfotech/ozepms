<?php

namespace App\Domain\Rates\Reference;

use App\Models\MealPlan;

/**
 * Global meal plans with OpenTravel Meal Plan Type (MPT) codes so they can be mapped to
 * channels later. Custom property meal plans live in the same table with a property_id.
 */
final class MealPlans
{
    /** code => [name, breakfast, lunch, dinner, all inclusive, MPT code] */
    public const PLANS = [
        'RO' => ['Room Only', false, false, false, false, '14'],
        'BB' => ['Bed & Breakfast', true, false, false, false, '3'],
        'HB' => ['Half Board', true, false, true, false, '12'],
        'FB' => ['Full Board', true, true, true, false, '10'],
        'AI' => ['All Inclusive', true, true, true, true, '1'],
        'LO' => ['Lunch Only', false, true, false, false, '21'],
        'DI' => ['Dinner Only', false, false, true, false, '22'],
        'BD' => ['Breakfast + Dinner', true, false, true, false, '17'],
    ];

    public static function seed(): void
    {
        foreach (array_keys(self::PLANS) as $code) {
            self::ensure($code);
        }
    }

    /** Returns the global meal plan, creating it when the reference data has not been seeded. */
    public static function ensure(string $code): MealPlan
    {
        [$name, $breakfast, $lunch, $dinner, $allInclusive, $ota] = self::PLANS[$code];

        $plan = MealPlan::query()->whereNull('property_id')->where('code', $code)->first() ?? new MealPlan(['code' => $code]);
        $plan->fill([
            'name' => $name,
            'includes_breakfast' => $breakfast,
            'includes_lunch' => $lunch,
            'includes_dinner' => $dinner,
            'is_all_inclusive' => $allInclusive,
            'ota_code' => $ota,
            'is_active' => true,
        ]);
        if ($plan->isDirty()) {
            $plan->save();
        }

        return $plan;
    }
}
