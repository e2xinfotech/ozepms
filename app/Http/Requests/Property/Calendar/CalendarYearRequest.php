<?php

namespace App\Http\Requests\Property\Calendar;

/** Year overview: twelve months from a month (Y-m or Y-m-d), with the room type / rate plan / status filters. */
class CalendarYearRequest extends CalendarGridRequest
{
    public function rules(): array
    {
        return ['from' => ['nullable', 'regex:/^\d{4}-\d{2}(-\d{2})?$/']] + self::filterRules();
    }
}
