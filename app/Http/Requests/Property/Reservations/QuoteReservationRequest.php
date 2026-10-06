<?php

namespace App\Http\Requests\Property\Reservations;

/** Price preview of the room rows of the reservation form (no guest needed). */
class QuoteReservationRequest extends SaveReservationRequest
{
    public function rules(): array
    {
        $rules = parent::rules();

        return array_filter($rules, fn ($key) => str_starts_with($key, 'rooms'), ARRAY_FILTER_USE_KEY)
            + ['reservation_id' => ['nullable', 'string', 'size:26'], 'promo_code' => $rules['promo_code'], 'source' => $rules['source'],
                'guest_country' => ['nullable', 'string', 'size:2']];
    }
}
