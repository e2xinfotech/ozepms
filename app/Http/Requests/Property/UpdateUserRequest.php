<?php

namespace App\Http\Requests\Property;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:80'],
            'phone_e164' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^\+[1-9]\d{6,14}$/'],
            'role' => ['sometimes', 'required', 'string', 'max:40'],
            'status' => ['sometimes', 'required', 'in:active,disabled'],
        ];
    }
}
