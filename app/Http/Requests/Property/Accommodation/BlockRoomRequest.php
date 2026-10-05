<?php

namespace App\Http\Requests\Property\Accommodation;

use App\Models\UnitBlock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Out of order / maintenance / owner hold. end_date is the first night the room is back in service. */
class BlockRoomRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'block_type' => ['required', Rule::in(UnitBlock::TYPES)],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after:start_date'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
