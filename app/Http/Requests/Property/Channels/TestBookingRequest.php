<?php

namespace App\Http\Requests\Property\Channels;

use Illuminate\Foundation\Http\FormRequest;

/** A booking sent from the Test Channel. */
class TestBookingRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'room_id' => ['required', 'string', 'max:64'],
            'rate_id' => ['nullable', 'string', 'max:64'],
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],
            'adults' => ['required', 'integer', 'min:1', 'max:20'],
            'price' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'first_name' => ['required', 'string', 'max:60'],
            'last_name' => ['required', 'string', 'max:60'],
            'email' => ['nullable', 'email', 'max:190'],
        ];
    }
}
