<?php

namespace App\Http\Requests\Property\Accommodation;

use Illuminate\Foundation\Http\FormRequest;

/** Default rate plan of a room type, chosen from the list dropdown (a product of that room type). */
class DefaultRatePlanRequest extends FormRequest
{
    public function rules(): array
    {
        return ['product_id' => ['required', 'string', 'max:26']];
    }
}
