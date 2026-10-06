<?php

namespace App\Http\Requests\Property;

use Illuminate\Foundation\Http\FormRequest;

/** Name of the new property and whether the PMS rooms are copied as well. */
class CopyPropertyRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'include_rooms' => ['sometimes', 'boolean'],
        ];
    }
}
