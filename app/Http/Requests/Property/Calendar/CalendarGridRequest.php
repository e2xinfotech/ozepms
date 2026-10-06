<?php

namespace App\Http\Requests\Property\Calendar;

use App\Domain\Inventory\Queries\CalendarQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** One calendar window: range (month | week) from a date, with optional filters (public ids). */
class CalendarGridRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'range' => ['nullable', Rule::in(CalendarQuery::RANGES)],
            'from' => ['nullable', 'regex:/^\d{4}-\d{2}(-\d{2})?$/'],
            'room_type' => ['nullable', 'string', 'max:26'],
            'unit' => ['nullable', 'string', 'max:26'],
            'rate_plan' => ['nullable', 'string', 'max:26'],
            'status' => ['nullable', Rule::in(CalendarQuery::STATUSES)],
        ];
    }

    /** @return array{room_type: ?string, unit: ?string, rate_plan: ?string, status: ?string} */
    public function filters(): array
    {
        return [
            'room_type' => $this->validated('room_type'),
            'unit' => $this->validated('unit'),
            'rate_plan' => $this->validated('rate_plan'),
            'status' => $this->validated('status'),
        ];
    }
}
