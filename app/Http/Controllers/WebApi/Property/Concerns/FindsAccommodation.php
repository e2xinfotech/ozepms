<?php

namespace App\Http\Controllers\WebApi\Property\Concerns;

use App\Models\Amenity;
use App\Models\CancellationPolicy;
use App\Models\MealPlan;
use App\Models\PhysicalUnit;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\TaxRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Looks up the property's records by public id. Every lookup goes through the tenant scope,
 * so another property's id behaves like an unknown id: 404 for ids in the URL and a field
 * error for ids in the request body.
 */
trait FindsAccommodation
{
    protected function roomTypeOr404(string $id): RoomType
    {
        return RoomType::query()->where('public_id', $id)->firstOrFail();
    }

    protected function ratePlanOr404(string $id): RatePlan
    {
        return RatePlan::query()->where('public_id', $id)->firstOrFail();
    }

    protected function taxRuleOr404(string $id): TaxRule
    {
        return TaxRule::query()->forProperty()->where('public_id', $id)->firstOrFail();
    }

    protected function amenityOr404(string $code): Amenity
    {
        return Amenity::query()->visibleToProperty()->where('code', $code)->firstOrFail();
    }

    protected function policyOr404(string $code): CancellationPolicy
    {
        return CancellationPolicy::query()->where('code', strtoupper($code))->firstOrFail();
    }

    protected function unitOr404(string $id): PhysicalUnit
    {
        return PhysicalUnit::query()->where('public_id', $id)->firstOrFail();
    }

    protected function roomTypeField(?string $id, string $field): RoomType
    {
        /** @var RoomType */
        return $this->fieldRecord(RoomType::query(), 'public_id', $id, $field);
    }

    protected function ratePlanField(?string $id, string $field): RatePlan
    {
        /** @var RatePlan */
        return $this->fieldRecord(RatePlan::query(), 'public_id', $id, $field);
    }

    protected function productField(?string $id, string $field): Product
    {
        /** @var Product */
        return $this->fieldRecord(Product::query(), 'public_id', $id, $field);
    }

    protected function mealPlanField(?string $code, string $field): MealPlan
    {
        /** @var MealPlan */
        return $this->fieldRecord(MealPlan::query()->visibleToProperty()->where('is_active', true)->orderByDesc('property_id'), 'code', $code, $field);
    }

    protected function policyField(?string $code, string $field): CancellationPolicy
    {
        /** @var CancellationPolicy */
        return $this->fieldRecord(CancellationPolicy::query(), 'code', $code === null ? null : strtoupper($code), $field);
    }

    /**
     * @param  list<string>  $ids
     * @return list<int>
     */
    protected function internalIds(Builder $query, array $ids, string $field): array
    {
        $found = (clone $query)->whereIn('public_id', $ids)->pluck('id', 'public_id');
        foreach ($ids as $i => $id) {
            if (! isset($found[$id])) {
                throw ValidationException::withMessages(["$field.$i" => __('validation.exists', ['attribute' => __('rooms.fields.record')])]);
            }
        }

        return array_values(array_map(fn ($id) => (int) $found[$id], $ids));
    }

    private function fieldRecord(Builder $query, string $column, ?string $value, string $field): Model
    {
        $record = $value === null || $value === '' ? null : $query->where($column, $value)->first();
        if (! $record) {
            throw ValidationException::withMessages([$field => __('validation.exists', ['attribute' => __('rooms.fields.record')])]);
        }

        return $record;
    }
}
