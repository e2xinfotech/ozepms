<?php

namespace App\Http\Requests\Property\Accommodation;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRoomRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:30'],
            'floor' => ['nullable', 'string', 'max:10'],
            'building' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:500'],
            'room_type_id' => ['sometimes', 'string', 'max:26'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
