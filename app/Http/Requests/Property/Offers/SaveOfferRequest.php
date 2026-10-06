<?php

namespace App\Http\Requests\Property\Offers;

use App\Models\Offer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create / update an offer. Cross-field rules (ranges, free nights, uniqueness) live in OfferAdminService. */
class SaveOfferRequest extends FormRequest
{
    public function rules(): array
    {
        $req = $this->isMethod('PUT') ? 'sometimes' : 'required';
        $date = ['nullable', 'date_format:Y-m-d'];
        $count = fn (int $max) => ['nullable', 'integer', 'min:0', 'max:'.$max];

        return [
            'name' => [$req, 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/'],
            'offer_type' => [$req, Rule::in(Offer::TYPES)],
            'description' => ['nullable', 'string', 'max:2000'],
            'promo_code' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/'],
            'discount_type' => [$req, Rule::in(Offer::DISCOUNT_TYPES)],
            'discount_value' => [$req, 'decimal:0,4', 'gt:0', 'max:9999999'],
            'min_nights' => ['nullable', 'integer', 'min:1', 'max:365'],
            'max_nights' => ['nullable', 'integer', 'min:1', 'max:365'],
            'min_amount' => ['nullable', 'decimal:0,2', 'min:0', 'max:99999999'],
            'booking_from' => $date, 'booking_to' => $date, 'stay_from' => $date, 'stay_to' => $date,
            'weekdays' => ['sometimes', 'integer', 'min:1', 'max:127'],
            'min_advance_days' => $count(730), 'max_advance_days' => $count(730),
            'priority' => ['sometimes', 'integer', 'min:-999', 'max:999'],
            'is_stackable' => ['sometimes', 'boolean'],
            'max_redemptions' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'on_pms' => ['sometimes', 'boolean'],
            'on_booking_engine' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'room_types' => ['sometimes', 'array', 'max:100'], 'room_types.*' => ['string', 'size:26'],
            'rate_plans' => ['sometimes', 'array', 'max:100'], 'rate_plans.*' => ['string', 'size:26'],
            'sources' => ['sometimes', 'array', 'max:50'], 'sources.*' => ['string', 'max:30'],
            'countries' => ['sometimes', 'array', 'max:250'], 'countries.*' => ['string', 'size:2'],
            'country_mode' => ['sometimes', Rule::in(['in', 'not_in'])],
            'min_adults' => ['nullable', 'integer', 'min:1', 'max:20'],
            'min_rooms' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
