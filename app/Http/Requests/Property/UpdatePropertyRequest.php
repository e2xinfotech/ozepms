<?php

namespace App\Http\Requests\Property;

use App\Http\Requests\Concerns\PropertyRules;
use Illuminate\Foundation\Http\FormRequest;

/** Property Configuration (settings) of the current property. */
class UpdatePropertyRequest extends FormRequest
{
    use PropertyRules;

    public function rules(): array
    {
        return $this->propertyRules();
    }

    /** @return array<string, mixed> */
    public function propertyFields(): array
    {
        return $this->propertyData();
    }
}
