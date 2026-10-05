<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/** Actions that only need the user to re-enter their password (e.g. turning off 2FA). */
class CurrentPasswordRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:200'],
        ];
    }
}
