<?php

namespace App\Http\Requests\Property\Billing;

use Illuminate\Foundation\Http\FormRequest;

class OnlinePaymentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'amount' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ];
    }
}
