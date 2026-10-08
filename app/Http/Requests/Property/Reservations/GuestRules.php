<?php

namespace App\Http\Requests\Property\Reservations;

use App\Models\Guest;
use Illuminate\Validation\Rule;

/** Guest profile fields, shared by the reservation form and the guest form. */
final class GuestRules
{
    public static function rules(string $prefix = '', bool $required = true): array
    {
        return [
            $prefix.'title' => ['nullable', Rule::in(Guest::TITLES)],
            $prefix.'guest_type' => ['nullable', Rule::in(Guest::TYPES)],
            $prefix.'first_name' => [$required ? 'required' : 'sometimes', 'string', 'max:80'],
            $prefix.'last_name' => ['nullable', 'string', 'max:80'],
            $prefix.'email' => ['nullable', 'email:rfc', 'max:190'],
            $prefix.'phone' => ['nullable', 'string', 'max:25', 'regex:/^[+\d][\d\s().-]{5,24}$/'],
            $prefix.'country_iso2' => ['nullable', 'string', 'size:2', 'exists:countries,iso2'],
            $prefix.'nationality_iso2' => ['nullable', 'string', 'size:2', 'exists:countries,iso2'],
            $prefix.'gender' => ['nullable', Rule::in(['female', 'male', 'other'])],
            $prefix.'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before:today'],
            $prefix.'address_line1' => ['nullable', 'string', 'max:190'],
            $prefix.'address_line2' => ['nullable', 'string', 'max:190'],
            $prefix.'city' => ['nullable', 'string', 'max:100'],
            $prefix.'postcode' => ['nullable', 'string', 'max:20'],
            $prefix.'company_name' => ['nullable', 'string', 'max:190'],
            $prefix.'company_tax_no' => ['nullable', 'string', 'max:30'],
            $prefix.'id_type' => ['nullable', Rule::in(Guest::ID_TYPES)],
            $prefix.'id_number' => ['nullable', 'string', 'max:40'],
            $prefix.'id_issuing_iso2' => ['nullable', 'string', 'size:2'],
            $prefix.'id_expiry' => ['nullable', 'date_format:Y-m-d'],
            $prefix.'is_vip' => ['nullable', 'boolean'],
            $prefix.'marketing_consent' => ['nullable', 'boolean'],
            $prefix.'notes' => ['nullable', 'string', 'max:1000'],
            $prefix.'preferences' => ['nullable', 'string', 'max:1000'],
            $prefix.'tags' => ['nullable', 'array', 'max:12'],
            $prefix.'tags.*' => ['string', 'max:30'],
        ];
    }

    public static function attributes(string $prefix = ''): array
    {
        $out = [];
        foreach (['first_name', 'last_name', 'email', 'phone', 'nationality_iso2', 'id_type', 'id_number', 'date_of_birth', 'company_name'] as $f) {
            $out[$prefix.$f] = __('guests.fields.'.$f);
        }

        return $out;
    }
}
