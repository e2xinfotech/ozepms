<?php

namespace App\Http\Requests\Property\Billing;

use Illuminate\Foundation\Http\FormRequest;

/** Extra / adjustment / discount posted to a folio. */
class PostChargeRequest extends FormRequest
{
    public function rules(): array
    {
        $money = ['regex:/^-?\d{1,10}(\.\d{1,4})?$/'];

        return [
            'type' => ['required', 'in:service,adjustment,discount'],
            'service_id' => ['nullable', 'string', 'size:26'],
            'description' => [$this->input('type') === 'service' && ! $this->input('service_id') ? 'required' : 'nullable', 'string', 'max:190'],
            'quantity' => ['nullable', 'regex:/^\d{1,6}(\.\d{1,2})?$/'],
            'unit_price' => [$this->filled('discount_percent') || $this->filled('service_id') ? 'nullable' : 'required', ...$money],
            'discount_percent' => ['nullable', 'regex:/^\d{1,3}(\.\d{1,2})?$/', 'prohibited_unless:type,discount'],
            'tax_category' => ['nullable', 'string', 'max:30'],
            'note' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', \Illuminate\Validation\Rule::in(\App\Models\Service::DEPARTMENTS)],
            'reference' => ['nullable', 'string', 'max:40'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            if ($this->filled('discount_percent') && is_scalar($this->input('discount_percent')) && (float) $this->input('discount_percent') > 100) {
                $validator->errors()->add('discount_percent', __('billing.errors.percent'));
            }
            if ($this->input('type') !== 'adjustment' && $this->filled('unit_price') && is_scalar($this->input('unit_price')) && str_starts_with((string) $this->input('unit_price'), '-')) {
                $validator->errors()->add('unit_price', __('billing.errors.amount'));
            }
        }];
    }
}
