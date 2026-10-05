<?php

namespace App\Http\Requests\Property\Accommodation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Several PMS rooms at once: a range ("101-120"), a prefix sequence or a plain quantity. */
class BulkStoreRoomsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'room_type_id' => ['required', 'string', 'max:26'],
            'mode' => ['required', Rule::in(['range', 'sequence', 'quantity'])],
            'range' => ['required_if:mode,range', 'nullable', 'string', 'max:40'],
            'prefix' => ['nullable', 'string', 'max:20'],
            'start' => ['required_if:mode,sequence', 'nullable', 'string', 'max:6', 'regex:/^\d+$/'],
            'count' => ['required_if:mode,sequence', 'nullable', 'integer', 'min:1', 'max:500'],
            'quantity' => ['required_if:mode,quantity', 'nullable', 'integer', 'min:1', 'max:500'],
            'floor' => ['nullable', 'string', 'max:10'],
        ];
    }
}
