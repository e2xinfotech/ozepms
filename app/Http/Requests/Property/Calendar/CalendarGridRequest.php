<?php

namespace App\Http\Requests\Property\Calendar;

use App\Domain\Inventory\Queries\CalendarQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One calendar window: range (day | week | month) from a date, with optional filters
 * (public ids, record status, availability, restriction, price range).
 */
class CalendarGridRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'range' => ['nullable', Rule::in(CalendarQuery::RANGES)],
            'from' => ['nullable', 'regex:/^\d{4}-\d{2}(-\d{2})?$/'],
        ] + self::filterRules();
    }

    /** @return array<string, list<mixed>> */
    public static function filterRules(): array
    {
        return [
            'room_type' => ['nullable', 'string', 'max:26'],
            'unit' => ['nullable', 'string', 'max:26'],
            'rate_plan' => ['nullable', 'string', 'max:26'],
            'status' => ['nullable', Rule::in(CalendarQuery::STATUSES)],
            'availability' => ['nullable', Rule::in(CalendarQuery::AVAILABILITY)],
            'restriction' => ['nullable', Rule::in(CalendarQuery::RESTRICTIONS)],
            'price_min' => ['nullable', 'decimal:0,2', 'min:0', 'max:99999999'],
            'price_max' => ['nullable', 'decimal:0,2', 'min:0', 'max:99999999'],
        ];
    }

    public function attributes(): array
    {
        return [
            'price_min' => __('calendar.filters.price_min'),
            'price_max' => __('calendar.filters.price_max'),
        ];
    }

    /** @return array<string, ?string> */
    public function filters(): array
    {
        return CalendarQuery::normalizeFilters($this->validated());
    }
}
