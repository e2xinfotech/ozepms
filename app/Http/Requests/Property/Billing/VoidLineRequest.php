<?php

namespace App\Http\Requests\Property\Billing;

use Illuminate\Foundation\Http\FormRequest;

class VoidLineRequest extends FormRequest
{
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:3', 'max:255']];
    }
}
