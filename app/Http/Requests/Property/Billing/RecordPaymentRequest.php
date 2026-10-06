<?php

namespace App\Http\Requests\Property\Billing;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordPaymentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'method' => ['required', Rule::in(Payment::MANUAL_METHODS)],
            'amount' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:255'],
            'is_deposit' => ['nullable', 'boolean'],
            'received_at' => ['nullable', 'date_format:Y-m-d'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ];
    }
}
