<?php

namespace App\Http\Requests\Property;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveRoleRequest extends FormRequest
{
    public const COLORS = ['blue', 'sky', 'teal', 'green', 'amber', 'orange', 'red', 'rose', 'pink', 'purple', 'violet', 'slate'];

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
            'color' => ['required', Rule::in(self::COLORS)],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::exists('permissions', 'key')->where('scope', 'property')],
        ];
    }
}
