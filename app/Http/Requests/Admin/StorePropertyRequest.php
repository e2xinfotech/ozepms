<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\PropertyRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Platform registration of a property together with its owner and plan. */
class StorePropertyRequest extends FormRequest
{
    use PropertyRules;

    public function rules(): array
    {
        return $this->propertyRules() + [
            'status' => ['required', Rule::in(['onboarding', 'active'])],
            'owner' => ['required', 'array'],
            'owner.name' => ['required', 'string', 'max:120'],
            'owner.email' => ['required', 'string', 'email', 'max:190'],
            'owner.phone_e164' => ['nullable', 'string', 'regex:/^\+[1-9]\d{6,14}$/'],
            'plan_id' => ['nullable', 'integer', Rule::exists('subscription_plans', 'id')->where('is_active', true)],
        ];
    }

    /** @return array<string, mixed> */
    public function propertyFields(): array
    {
        return $this->propertyData() + ['status' => $this->validated('status')];
    }

    /** @return array{name: string, email: string, phone_e164: ?string} */
    public function owner(): array
    {
        $owner = $this->validated('owner');

        return ['name' => $owner['name'], 'email' => $owner['email'], 'phone_e164' => $owner['phone_e164'] ?? null];
    }
}
