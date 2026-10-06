<?php

namespace App\Http\Requests\Property\Accommodation;

use App\Models\ProductOccupancyRule;
use Illuminate\Validation\Rule;

/** Validation rules for an occupancy pricing list, shared by the room type and rate plan forms. */
final class OccupancyRules
{
    /** @return array<string, mixed> */
    public static function rules(string $prefix): array
    {
        return [
            $prefix => ['sometimes', 'array', 'max:20'],
            "$prefix.*.guest_type" => ['required', Rule::in(ProductOccupancyRule::GUEST_TYPES)],
            "$prefix.*.guest_count" => ['required', 'integer', 'min:1', 'max:20'],
            "$prefix.*.age_band" => ['nullable', Rule::in(['infant', 'child', 'teen'])],
            "$prefix.*.adjust_type" => ['required', Rule::in(['fixed', 'percent'])],
            "$prefix.*.adjust_value" => ['required', 'decimal:0,4', 'min:-99999999', 'max:99999999'],
        ];
    }
}
