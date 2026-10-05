<?php

namespace App\Http\Requests\Property\Accommodation;

use Illuminate\Foundation\Http\FormRequest;

/** Activate / deactivate a record. */
class StatusRequest extends FormRequest
{
    public function rules(): array
    {
        return ['is_active' => ['required', 'boolean']];
    }
}
