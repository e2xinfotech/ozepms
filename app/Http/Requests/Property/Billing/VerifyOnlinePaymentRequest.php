<?php

namespace App\Http\Requests\Property\Billing;

use Illuminate\Foundation\Http\FormRequest;

/** Fields returned by Razorpay Checkout after a successful payment. */
class VerifyOnlinePaymentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'razorpay_order_id' => ['required', 'string', 'max:64'],
            'razorpay_payment_id' => ['required', 'string', 'max:64'],
            'razorpay_signature' => ['required', 'string', 'max:128'],
        ];
    }
}
