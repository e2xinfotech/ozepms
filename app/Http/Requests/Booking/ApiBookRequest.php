<?php

namespace App\Http\Requests\Booking;

/** /api/v1/reservations: like the booking page, without the page-only fields; optional client reference. */
class ApiBookRequest extends BookRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['accept_terms'], $rules['website']);
        $rules['quoted_total'] = ['nullable', 'decimal:0,2', 'min:0', 'max:999999999999'];
        $rules['idempotency_key'] = ['nullable', 'string', 'max:64'];
        $rules['external_ref'] = ['nullable', 'string', 'max:60'];

        return $rules;
    }
}
