<?php

namespace App\Http\Requests\Property\Accommodation;

use App\Models\CancellationPolicyRule;
use App\Support\PropertyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCancellationPolicyRequest extends FormRequest
{
    public function rules(): array
    {
        $updating = $this->isMethod('PUT');
        $req = $updating ? 'sometimes' : 'required';

        return [
            'code' => [$req, 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('cancellation_policies', 'code')
                ->where('property_id', app(PropertyContext::class)->id())
                ->ignore($updating ? strtoupper((string) $this->route('policy')) : null, 'code')],
            'name' => [$req, 'string', 'max:120'],
            'is_refundable' => [$req, 'boolean'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
            'rules' => [$req, 'array', 'max:10'],
            'rules.*.applies_to' => ['required', Rule::in(['cancellation', 'no_show'])],
            'rules.*.hours_before_arrival' => ['required', 'integer', 'min:0', 'max:8760'],
            'rules.*.charge_type' => ['required', Rule::in(CancellationPolicyRule::CHARGE_TYPES)],
            'rules.*.charge_value' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ];
    }
}
