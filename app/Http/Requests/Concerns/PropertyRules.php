<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;

/**
 * Validation of the property form, shared by onboarding, property settings and
 * the platform property form so the three never drift apart.
 */
trait PropertyRules
{
    /** @return array<string, mixed> */
    protected function propertyRules(): array
    {
        $country = is_string($this->input('country_iso2')) ? (string) $this->input('country_iso2') : '';

        return [
            'name' => ['required', 'string', 'max:150'],
            'tagline' => ['nullable', 'string', 'max:150'],
            'legal_name' => ['nullable', 'string', 'max:190'],
            'property_type_id' => ['required', 'integer', Rule::exists('property_types', 'id')->where('is_active', true)],
            'star_rating' => ['nullable', 'integer', 'between:1,5'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\-\s.]{4,30}$/'],
            'email' => ['nullable', 'email', 'max:190'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'contact_person' => ['nullable', 'string', 'max:120'],

            'country_iso2' => ['required', 'string', 'size:2', Rule::exists('countries', 'iso2')->where('is_active', true)],
            'state_id' => ['nullable', 'integer', Rule::exists('states', 'id')->where('country_iso2', $country)],
            'city' => ['nullable', 'string', 'max:100'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'address_line1' => ['nullable', 'string', 'max:190'],
            'address_line2' => ['nullable', 'string', 'max:190'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'maps_url' => ['nullable', 'url:http,https', 'max:500'],

            'currency_code' => ['required', 'string', 'size:3', Rule::exists('currencies', 'code')->where('is_active', true)],
            'timezone' => ['required', 'string', 'max:64', 'timezone:all'],
            'date_format' => ['nullable', Rule::in(config('ozepms.property.date_formats'))],
            'number_format' => ['nullable', Rule::in(config('ozepms.property.number_formats'))],
            'week_start' => ['nullable', 'integer', Rule::in([0, 1, 6])],
            'check_in_time' => ['nullable', 'date_format:H:i'],
            'check_out_time' => ['nullable', 'date_format:H:i'],
            'tax_registration_no' => ['nullable', 'string', 'max:30'],

            'default_language' => ['required', 'string', Rule::exists('languages', 'code')->where('is_active', true)],
            'languages' => ['nullable', 'array', 'max:20'],
            'languages.*' => ['string', 'distinct', Rule::exists('languages', 'code')->where('is_active', true)],
        ];
    }

    /** Validated property fields with empty optional settings removed, so column defaults apply. */
    protected function propertyData(): array
    {
        $data = collect($this->validated())->only(array_keys($this->propertyRules()))->all();

        foreach (['date_format', 'number_format', 'week_start', 'check_in_time', 'check_out_time'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] === null) {
                unset($data[$key]);
            }
        }
        unset($data['languages.*']);

        return $data;
    }
}
