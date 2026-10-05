<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class TwoFactorChallengeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // 6-digit app code or an XXXXX-XXXXX recovery code.
            'code' => ['required', 'string', 'max:20'],
        ];
    }
}
