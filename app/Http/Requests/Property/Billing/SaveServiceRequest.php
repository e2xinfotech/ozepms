<?php

namespace App\Http\Requests\Property\Billing;

use App\Models\Service;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveServiceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'tax_category' => ['required', 'string', Rule::exists('tax_categories', 'code')],
            'sac_hsn_code' => ['nullable', 'string', 'max:10', 'regex:/^[0-9A-Za-z]*$/'],
            'posting_rule' => ['required', Rule::in(Service::POSTING_RULES)],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }
}
