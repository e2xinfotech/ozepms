<?php

namespace App\Http\Requests\Property\Accommodation;

use Illuminate\Foundation\Http\FormRequest;

/** A property's own meal plan, added from the rate plan form. */
class SaveMealPlanRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:80'],
            'includes_breakfast' => ['sometimes', 'boolean'],
            'includes_lunch' => ['sometimes', 'boolean'],
            'includes_dinner' => ['sometimes', 'boolean'],
            'is_all_inclusive' => ['sometimes', 'boolean'],
        ];
    }
}
