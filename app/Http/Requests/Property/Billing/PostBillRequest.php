<?php

namespace App\Http\Requests\Property\Billing;

use App\Models\Service;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A bill from an outlet (restaurant, bar …) with several items. */
class PostBillRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'department' => ['required', Rule::in(Service::DEPARTMENTS)],
            'reference' => ['nullable', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['required', 'string', 'max:40'],
            'lines' => ['required', 'array', 'min:1', 'max:40'],
            'lines.*.service_id' => ['nullable', 'string', 'size:26'],
            'lines.*.description' => ['nullable', 'string', 'max:190', 'required_without:lines.*.service_id'],
            'lines.*.quantity' => ['required', 'regex:/^\d{1,6}(\.\d{1,2})?$/'],
            'lines.*.unit_price' => ['nullable', 'regex:/^\d{1,10}(\.\d{1,4})?$/', 'required_without:lines.*.service_id'],
            'lines.*.tax_category' => ['nullable', 'string', 'max:30'],
        ];
    }
}
