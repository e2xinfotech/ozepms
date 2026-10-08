<?php

namespace App\Http\Requests\Property\Housekeeping;

use Illuminate\Foundation\Http\FormRequest;

/** Rooms to give to one person (staff_id null removes the assignment). */
class AssignRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'rooms' => ['required', 'array', 'min:1', 'max:500'],
            'rooms.*' => ['string', 'max:26'],
            'staff_id' => ['nullable', 'string', 'max:26'],
        ];
    }
}
