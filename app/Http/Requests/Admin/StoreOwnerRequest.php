<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Platform staff create a property owner (before the property). */
class StoreOwnerRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:190'],
            'phone_e164' => ['nullable', 'string', 'max:20', 'regex:/^\+[1-9]\d{6,14}$/'],
            'job_title' => ['nullable', 'string', 'max:80'],
            'locale' => ['nullable', Rule::in(array_keys(config('ozepms.locales.available')))],
        ];
    }
}
