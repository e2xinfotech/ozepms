<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;

/** Public availability search (booking engine). */
class SearchRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d'],
            'adults' => ['required', 'integer', 'min:1', 'max:20'],
            'children' => ['sometimes', 'integer', 'min:0', 'max:10'],
            'infants' => ['sometimes', 'integer', 'min:0', 'max:6'],
            'rooms' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('ozepms.booking_engine.max_rooms')],
            'promo_code' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9_\- ]+$/'],
        ];
    }
}
