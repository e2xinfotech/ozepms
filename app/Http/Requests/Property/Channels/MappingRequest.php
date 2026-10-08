<?php

namespace App\Http\Requests\Property\Channels;

use Illuminate\Foundation\Http\FormRequest;

class MappingRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'rooms' => ['present', 'array', 'max:300'],
            'rooms.*' => ['nullable', 'string', 'max:64'],
            'rates' => ['present', 'array', 'max:2000'],
            'rates.*.product_id' => ['required', 'integer', 'min:1', 'max:9223372036854775807'],
            'rates.*.external_rate_id' => ['nullable', 'string', 'max:64'],
            'rates.*.markup_type' => ['required', 'in:none,percent,fixed'],
            'rates.*.markup_value' => ['nullable', 'numeric', 'min:-100000', 'max:100000'],
        ];
    }
}
