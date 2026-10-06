<?php

namespace App\Http\Requests\Property\Billing;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RefundPaymentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'amount' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'method' => ['nullable', Rule::in(Payment::MANUAL_METHODS)],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['required', 'string', 'min:3', 'max:255'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ];
    }
}
