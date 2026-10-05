<?php

namespace App\Http\Requests\Admin;

use App\Domain\Subscription\PlanService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePlanRequest extends FormRequest
{
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'code' => $creating
                ? ['required', 'string', 'max:30', 'regex:/^[a-z0-9_]+$/', Rule::unique('subscription_plans', 'code')]
                : ['prohibited'],
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'currency_code' => ['required', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'billing_cycle' => ['required', Rule::in(['monthly', 'quarterly', 'yearly'])],
            'trial_days' => ['required', 'integer', 'between:0,365'],
            'grace_days' => ['required', 'integer', 'between:0,90'],
            'max_room_types' => ['nullable', 'integer', 'min:1', 'max:65000'],
            'max_units' => ['nullable', 'integer', 'min:1', 'max:65000'],
            'max_users' => ['nullable', 'integer', 'min:1', 'max:65000'],
            'features' => ['present', 'array'],
            'features.*' => ['boolean'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public function planData(): array
    {
        $data = $this->safe()->except(['features']);
        $data['features'] = collect((array) $this->input('features'))->only(PlanService::FEATURES)->all();

        return $data;
    }
}
