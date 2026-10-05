<?php

namespace App\Http\Requests\Property\Accommodation;

use App\Models\Amenity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveAmenityRequest extends FormRequest
{
    public function rules(): array
    {
        $req = $this->isMethod('PUT') ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'max:80'],
            'category' => [$req, Rule::in(Amenity::CATEGORIES)],
            'icon' => ['nullable', 'string', 'max:40', 'regex:/^[a-z0-9-]+$/'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
