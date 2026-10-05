<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangePropertyStatusRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['onboarding', 'active', 'suspended', 'inactive'])],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
