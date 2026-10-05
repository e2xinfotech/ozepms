<?php

namespace App\Http\Requests\Property\Accommodation;

use App\Models\Product;
use App\Models\RatePlan;
use App\Support\PropertyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create / update a rate plan with its room type links (products). */
class SaveRatePlanRequest extends FormRequest
{
    public function rules(): array
    {
        $updating = $this->isMethod('PUT');
        $req = $updating ? 'sometimes' : 'required';

        return [
            'code' => [$req, 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('rate_plans', 'code')
                ->where('property_id', app(PropertyContext::class)->id())
                ->ignore($updating ? (string) $this->route('ratePlan') : null, 'public_id')],
            'name' => [$req, 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'meal_plan' => [$req, 'string', 'max:20'],
            'cancellation_policy' => [$req, 'string', 'max:30'],
            'payment_type' => ['sometimes', Rule::in(RatePlan::PAYMENT_TYPES)],
            'deposit_value' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'default_min_los' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'default_max_los' => ['nullable', 'integer', 'min:1', 'max:365'],
            'min_advance_days' => ['nullable', 'integer', 'min:0', 'max:730'],
            'max_advance_days' => ['nullable', 'integer', 'min:0', 'max:730'],
            'sell_on_pms' => ['sometimes', 'boolean'],
            'sell_on_booking_engine' => ['sometimes', 'boolean'],
            'sell_on_channels' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],

            'room_types' => ['sometimes', 'array', 'max:200'],
            'room_types.*.room_type_id' => ['required', 'string', 'distinct', 'max:26'],
            'room_types.*.enabled' => ['required', 'boolean'],
            'room_types.*.pricing_mode' => ['nullable', Rule::in(['manual', 'derived'])],
            'room_types.*.default_price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'room_types.*.parent_rate_plan_id' => ['nullable', 'string', 'max:26'],
            'room_types.*.adjust_type' => ['nullable', Rule::in(Product::ADJUST_TYPES)],
            'room_types.*.adjust_value' => ['nullable', 'numeric', 'min:-99999999', 'max:99999999'],
            ...OccupancyRules::rules('room_types.*.occupancy_rules'),
        ];
    }
}
