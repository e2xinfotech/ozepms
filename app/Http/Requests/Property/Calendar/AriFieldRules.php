<?php

namespace App\Http\Requests\Property\Calendar;

/**
 * Validation of the values a calendar edit may change. Every field is optional; missing or null
 * leaves the stored value unchanged. Cross-field rules (min ≤ max, at least one value) are
 * checked by App\Domain\Inventory\AriChangeSet so the page gets the same messages everywhere.
 */
trait AriFieldRules
{
    /** @return array<string, list<mixed>> */
    protected function fieldRules(): array
    {
        return [
            'price' => ['nullable', 'decimal:0,2', 'min:0', 'max:99999999'],
            'occupancy_prices' => ['nullable', 'array', 'max:20'],
            'occupancy_prices.*' => ['nullable', 'decimal:0,2', 'min:0', 'max:99999999'],
            'sell_limit' => ['nullable', function (string $attribute, mixed $value, \Closure $fail) {
                if ($value !== 'none' && $value !== false && (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0 || (int) $value > 9999)) {
                    $fail(__('validation.integer', ['attribute' => __('calendar.fields.sell_limit')]));
                }
            }],
            'stop_sell' => ['nullable', 'boolean'],
            'closed' => ['nullable', 'boolean'],
            'cta' => ['nullable', 'boolean'],
            'ctd' => ['nullable', 'boolean'],
            'min_los' => ['nullable', 'integer', 'min:0', 'max:999'],
            'max_los' => ['nullable', 'integer', 'min:0', 'max:999'],
            'min_advance' => ['nullable', 'integer', 'min:0', 'max:999'],
            'max_advance' => ['nullable', 'integer', 'min:0', 'max:999'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'price' => __('calendar.fields.price'),
            'occupancy_prices.*' => __('calendar.fields.occupancy_price'),
            'sell_limit' => __('calendar.fields.sell_limit'),
            'min_los' => __('calendar.fields.min_los'),
            'max_los' => __('calendar.fields.max_los'),
            'min_advance' => __('calendar.fields.min_advance'),
            'max_advance' => __('calendar.fields.max_advance'),
            'date' => __('calendar.fields.date'),
            'date_from' => __('calendar.fields.date_from'),
            'date_to' => __('calendar.fields.date_to'),
            'weekdays' => __('calendar.fields.weekdays'),
        ];
    }

    /** Only the value fields, as sent (for AriChangeSet::fromArray). */
    public function ariValues(): array
    {
        return array_intersect_key($this->validated(), array_flip(['price', 'occupancy_prices', 'sell_limit', 'stop_sell', 'closed', 'cta', 'ctd', 'min_los', 'max_los', 'min_advance', 'max_advance']));
    }
}
