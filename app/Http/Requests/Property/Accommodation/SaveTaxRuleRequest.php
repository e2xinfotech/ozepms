<?php

namespace App\Http\Requests\Property\Accommodation;

use App\Models\TaxRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A tax, service charge or fee (Taxes & Fees edit panel). Code uniqueness is checked by the service. */
class SaveTaxRuleRequest extends FormRequest
{
    public function rules(): array
    {
        $req = $this->isMethod('PUT') ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'max:80'],
            'code' => [$req, 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/'],
            'kind' => [$req, Rule::in(TaxRule::KINDS)],
            'tax_type' => ['sometimes', Rule::in(TaxRule::TAX_TYPES)],
            'calc_type' => [$req, Rule::in(TaxRule::CALC_TYPES)],
            'rate' => [$req, 'numeric', 'min:0', 'max:99999'],
            'apply_to' => [$req, 'array', 'min:1'],
            'apply_to.*' => [Rule::in(TaxRule::APPLY_TO), 'distinct'],
            'description' => ['nullable', 'string', 'max:500'],
            'slab_min' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'slab_max' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'component_mode' => ['sometimes', Rule::in(['single', 'gst_split'])],
            'is_inclusive' => ['sometimes', 'boolean'],
            'is_compound' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'effective_from' => ['sometimes', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d'],
            'is_default_for_new_room_types' => ['sometimes', 'boolean'],
            'include_in_displayed_rate' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'room_types' => ['sometimes', 'array', 'max:200'],
            'room_types.*' => ['string', 'distinct', 'max:26'],
            'rate_plans' => ['sometimes', 'array', 'max:200'],
            'rate_plans.*' => ['string', 'distinct', 'max:26'],
        ];
    }
}
