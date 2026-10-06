<?php

namespace App\Http\Requests\Property\Reservations;

/** Price preview of the room rows of the reservation form (no guest needed). */
class QuoteReservationRequest extends SaveReservationRequest
{
    public function rules(): array
    {
        return array_filter(parent::rules(), fn ($key) => str_starts_with($key, 'rooms'), ARRAY_FILTER_USE_KEY)
            + ['reservation_id' => ['nullable', 'string', 'size:26']];
    }
}
