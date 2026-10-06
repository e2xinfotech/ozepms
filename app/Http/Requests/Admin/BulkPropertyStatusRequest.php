<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Bulk Actions on the platform property list: activate, deactivate or suspend the selected properties. */
class BulkPropertyStatusRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'codes' => ['required', 'array', 'min:1', 'max:100'],
            'codes.*' => ['required', 'string', 'distinct', Rule::exists('properties', 'code')->whereNull('deleted_at')],
            'status' => ['required', Rule::in(['active', 'inactive', 'suspended'])],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
