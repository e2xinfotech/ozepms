<?php

namespace App\Http\Requests\Booking;

/** Public booking: the search fields + the chosen room type / rate plan + the guest. */
class BookRequest extends SearchRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'room_type_id' => ['required', 'string', 'size:26'],
            'rate_plan_id' => ['required', 'string', 'size:26'],
            'quoted_total' => ['required', 'decimal:0,2', 'min:0', 'max:999999999999'],
            'idempotency_key' => ['required', 'string', 'max:64'],
            'guest.first_name' => ['required', 'string', 'max:80'],
            'guest.last_name' => ['required', 'string', 'max:80'],
            'guest.email' => ['required', 'email:rfc', 'max:150'],
            'guest.phone' => ['required', 'string', 'max:30', 'regex:/^[+0-9 ()\-]{6,30}$/'],
            'guest.nationality_iso2' => ['nullable', 'string', 'size:2', 'exists:countries,iso2'],
            'special_requests' => ['nullable', 'string', 'max:1000'],
            'arrival_time' => ['nullable', 'date_format:H:i'],
            'accept_terms' => ['accepted'],
            // Honeypot: real guests never see or fill this field.
            'website' => ['prohibited'],
        ];
    }

    public function attributes(): array
    {
        return [
            'guest.first_name' => __('booking.checkout.first_name'), 'guest.last_name' => __('booking.checkout.last_name'),
            'guest.email' => __('booking.checkout.email'), 'guest.phone' => __('booking.checkout.phone'),
            'guest.nationality_iso2' => __('booking.checkout.country'), 'arrival_time' => __('booking.checkout.arrival_time'),
            'special_requests' => __('booking.checkout.requests'),
        ];
    }

    public function messages(): array
    {
        return ['accept_terms.accepted' => __('booking.checkout.terms_required')];
    }
}
