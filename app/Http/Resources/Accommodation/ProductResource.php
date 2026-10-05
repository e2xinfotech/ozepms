<?php

namespace App\Http\Resources\Accommodation;

use App\Domain\Rates\DerivedPrice;
use App\Models\Product;
use App\Models\ProductOccupancyRule;
use Illuminate\Support\Facades\DB;

/** Shape of one room type ↔ rate plan link (product) inside room type and rate plan payloads. */
final class ProductResource
{
    /** @return array<string, mixed> */
    public static function shape(Product $p): array
    {
        static $bands = [];
        $propertyId = (int) $p->property_id;
        $bands[$propertyId] ??= DB::table('property_age_bands')->where('property_id', $propertyId)->pluck('code', 'id')->all();

        return [
            'id' => $p->public_id,
            'room_type' => $p->roomType?->public_id,
            'room_type_name' => $p->roomType?->name,
            'room_type_code' => $p->roomType?->code,
            'rate_plan' => $p->ratePlan?->public_id,
            'rate_plan_name' => $p->ratePlan?->name,
            'rate_plan_code' => $p->ratePlan?->code,
            'pricing_mode' => $p->pricing_mode,
            'default_price' => $p->default_price,
            'base_price' => DerivedPrice::basePrice($p),
            'parent_rate_plan' => $p->parent?->ratePlan?->public_id,
            'parent_rate_plan_name' => $p->parent?->ratePlan?->name,
            'adjust_type' => $p->adjust_type,
            'adjust_value' => $p->adjust_value,
            'is_default' => $p->is_default,
            'is_active' => $p->is_active,
            'occupancy_rules' => $p->occupancyRules->map(fn (ProductOccupancyRule $r) => [
                'guest_type' => $r->guest_type,
                'guest_count' => $r->guest_count,
                'age_band' => $r->age_band_id ? ($bands[$propertyId][$r->age_band_id] ?? null) : null,
                'adjust_type' => $r->adjust_type,
                'adjust_value' => $r->adjust_value,
            ])->values()->all(),
        ];
    }
}
