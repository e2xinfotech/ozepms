<?php

namespace App\Http\Requests\Property\Accommodation;

use App\Models\PhysicalUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class HousekeepingRequest extends FormRequest
{
    public function rules(): array
    {
        return ['housekeeping_status' => ['required', Rule::in(PhysicalUnit::HOUSEKEEPING)]];
    }
}
