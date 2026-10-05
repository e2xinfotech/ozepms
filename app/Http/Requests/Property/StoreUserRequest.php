<?php

namespace App\Http\Requests\Property;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:190'],
            'job_title' => ['nullable', 'string', 'max:80'],
            'phone_e164' => ['nullable', 'string', 'regex:/^\+[1-9]\d{6,14}$/'],
            'locale' => ['nullable', Rule::in(array_keys(config('ozepms.locales.available')))],
            'role' => ['required', 'string', 'max:40'],
        ];
    }
}
