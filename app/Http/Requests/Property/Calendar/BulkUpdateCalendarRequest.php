<?php

namespace App\Http\Requests\Property\Calendar;

/** "Bulk Update" drawer: date range, weekdays, room types and/or rate plans, values. */
class BulkUpdateCalendarRequest extends UpdateCalendarRangeRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'weekdays' => ['nullable', 'array', 'max:7'],
            'weekdays.*' => ['integer', 'between:1,7', 'distinct'],
            // Rate plans chosen in the drawer apply to every selected room type that sells them.
            'rate_plan_ids' => ['nullable', 'array', 'max:200'],
            'rate_plan_ids.*' => ['string', 'max:26'],
        ];
    }
}
