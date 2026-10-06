<?php

namespace App\Http\Requests\Property\Accommodation;

use Illuminate\Foundation\Http\FormRequest;

/** Tax calculator on the Taxes & Fees screen: one room charge for a number of nights. */
class TaxPreviewRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'tariff' => ['required', 'decimal:0,2', 'min:0', 'max:99999999'],
            'nights' => ['required', 'integer', 'min:1', 'max:365'],
            'persons' => ['sometimes', 'integer', 'min:1', 'max:40'],
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'guest_state' => ['nullable', 'string', 'max:10'],
        ];
    }
}
