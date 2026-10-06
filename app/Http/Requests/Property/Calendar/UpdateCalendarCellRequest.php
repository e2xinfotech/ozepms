<?php

namespace App\Http\Requests\Property\Calendar;

use Illuminate\Foundation\Http\FormRequest;

/** Inline edit of one calendar cell: one night of one room type (availability) or one product (rate row). */
class UpdateCalendarCellRequest extends FormRequest
{
    use AriFieldRules;

    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'room_type_id' => ['required_without:product_id', 'nullable', 'string', 'max:26'],
            'product_id' => ['required_without:room_type_id', 'nullable', 'string', 'max:26'],
        ] + $this->fieldRules();
    }
}
