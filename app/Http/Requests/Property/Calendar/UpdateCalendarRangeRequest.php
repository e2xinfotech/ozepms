<?php

namespace App\Http\Requests\Property\Calendar;

use Illuminate\Foundation\Http\FormRequest;

/** Drag-selected range on the calendar: consecutive nights of the selected rows. */
class UpdateCalendarRangeRequest extends FormRequest
{
    use AriFieldRules;

    public function rules(): array
    {
        return [
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'room_type_ids' => ['nullable', 'array', 'max:200'],
            'room_type_ids.*' => ['string', 'max:26'],
            'product_ids' => ['nullable', 'array', 'max:500'],
            'product_ids.*' => ['string', 'max:26'],
        ] + $this->fieldRules();
    }
}
