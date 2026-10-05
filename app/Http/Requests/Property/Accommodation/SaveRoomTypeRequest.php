<?php

namespace App\Http\Requests\Property\Accommodation;

use App\Models\Product;
use App\Models\RoomType;
use App\Support\PropertyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create / update a room type (POST creates, PUT updates; PUT accepts partial payloads). */
class SaveRoomTypeRequest extends FormRequest
{
    public function rules(): array
    {
        $updating = $this->isMethod('PUT');
        $req = $updating ? 'sometimes' : 'required';

        return [
            'code' => [$req, 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('room_types', 'code')
                ->where('property_id', app(PropertyContext::class)->id())
                ->ignore($updating ? (string) $this->route('roomType') : null, 'public_id')],
            'name' => [$req, 'string', 'max:120'],
            'category' => ['nullable', Rule::in(RoomType::CATEGORIES)],
            'description' => ['nullable', 'string', 'max:5000'],
            'base_adults' => [$req, 'integer', 'min:1', 'max:20'],
            'max_adults' => [$req, 'integer', 'min:1', 'max:20'],
            'max_children' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'max_infants' => ['sometimes', 'integer', 'min:0', 'max:10'],
            'max_occupancy' => [$req, 'integer', 'min:1', 'max:40'],
            'extra_bed_allowed' => ['sometimes', 'boolean'],
            'max_extra_beds' => ['sometimes', 'integer', 'min:0', 'max:5'],
            'size_value' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'size_unit' => ['nullable', 'required_with:size_value', Rule::in(['sqm', 'sqft'])],
            'smoking_policy' => ['sometimes', Rule::in(['non_smoking', 'smoking', 'both'])],
            'view_label' => ['nullable', 'string', 'max:60'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['sometimes', 'boolean'],

            'beds' => ['sometimes', 'array', 'max:10'],
            'beds.*.bed_type' => ['required', 'string', 'distinct', 'max:30'],
            'beds.*.quantity' => ['required', 'integer', 'min:1', 'max:10'],
            'amenities' => ['sometimes', 'array', 'max:100'],
            'amenities.*' => ['string', 'distinct', 'max:40'],

            'quantity' => ['nullable', 'integer', 'min:0', 'max:500'],
            'floor' => ['nullable', 'string', 'max:10'],
            'units' => ['sometimes', 'array', 'max:500'],
            'units.*.id' => ['nullable', 'string', 'max:26'],
            'units.*.name' => ['required', 'string', 'max:30'],
            'units.*.floor' => ['nullable', 'string', 'max:10'],
            'units.*.is_active' => ['sometimes', 'boolean'],

            'products' => ['sometimes', 'array', 'max:100'],
            'products.*.rate_plan_id' => ['required', 'string', 'distinct', 'max:26'],
            'products.*.enabled' => ['required', 'boolean'],
            'products.*.pricing_mode' => ['nullable', Rule::in(['manual', 'derived'])],
            'products.*.default_price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'products.*.parent_rate_plan_id' => ['nullable', 'string', 'max:26'],
            'products.*.adjust_type' => ['nullable', Rule::in(Product::ADJUST_TYPES)],
            'products.*.adjust_value' => ['nullable', 'numeric', 'min:-99999999', 'max:99999999'],
            'products.*.is_default' => ['sometimes', 'boolean'],
            ...OccupancyRules::rules('products.*.occupancy_rules'),
        ];
    }

    public function attributes(): array
    {
        return [
            'units.*.name' => __('rooms.fields.room_name'),
            'products.*.default_price' => __('rates.fields.price'),
        ];
    }
}
