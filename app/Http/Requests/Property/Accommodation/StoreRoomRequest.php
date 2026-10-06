<?php

namespace App\Http\Requests\Property\Accommodation;

use Illuminate\Foundation\Http\FormRequest;

/** One PMS room. */
class StoreRoomRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'room_type_id' => ['required', 'string', 'max:26'],
            'name' => ['required', 'string', 'max:30'],
            'floor' => ['nullable', 'string', 'max:10'],
            'building' => ['nullable', 'string', 'max:40'],
            'amenities' => ['sometimes', 'array', 'max:200'],
            'amenities.*' => ['string', 'max:40'],
        ];
    }
}
