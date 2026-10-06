<?php

namespace App\Http\Requests\Property\Reservations;

use App\Domain\Reservations\ReservationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create (POST) or replace (PUT) a reservation: guest, stay details and room rows. */
class SaveReservationRequest extends FormRequest
{
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $req = $creating ? 'required' : 'sometimes';

        return array_merge([
            'idempotency_key' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.:-]+$/'],
            'status' => ['sometimes', Rule::in(['confirmed', 'pending', 'inquiry'])],
            'source' => ['sometimes', 'nullable', 'string', 'max:30'],
            'promo_code' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9_\- ]+$/'],
            'arrival_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'departure_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'purpose' => ['sometimes', 'nullable', Rule::in(['leisure', 'business', 'conference', 'wedding', 'transit', 'other'])],
            'market' => ['sometimes', 'nullable', 'string', 'max:30'],
            'travel_agent' => ['sometimes', 'nullable', 'string', 'max:120'],
            'company_name' => ['sometimes', 'nullable', 'string', 'max:190'],
            'channel_ref' => ['sometimes', 'nullable', 'string', 'max:64'],
            'special_requests' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'internal_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],

            'guest_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'guest' => [$creating ? 'required_without:guest_id' : 'sometimes', 'array'],
            'companions' => ['sometimes', 'nullable', 'array', 'max:20'],
            'companions.*.first_name' => ['nullable', 'string', 'max:80'],
            'companions.*.last_name' => ['nullable', 'string', 'max:80'],
            'companions.*.nationality_iso2' => ['nullable', 'string', 'size:2'],

            'rooms' => [$req, 'array', 'min:1', 'max:'.ReservationService::MAX_ROOMS],
            'rooms.*.id' => ['nullable', 'string', 'size:26'],
            'rooms.*.room_type_id' => ['required', 'string', 'size:26'],
            'rooms.*.rate_plan_id' => ['required', 'string', 'size:26'],
            'rooms.*.unit_id' => ['nullable', 'string', 'size:26'],
            'rooms.*.check_in' => ['required', 'date_format:Y-m-d'],
            'rooms.*.check_out' => ['required', 'date_format:Y-m-d', 'after:rooms.*.check_in'],
            'rooms.*.adults' => ['required', 'integer', 'min:1', 'max:20'],
            'rooms.*.children' => ['nullable', 'integer', 'min:0', 'max:10'],
            'rooms.*.infants' => ['nullable', 'integer', 'min:0', 'max:10'],
            'rooms.*.child_ages' => ['nullable', 'array', 'max:20'],
            'rooms.*.child_ages.*' => ['integer', 'min:0', 'max:17'],
            'rooms.*.rate' => ['nullable', 'decimal:0,2', 'min:0', 'max:9999999'],
        ], GuestRules::rules('guest.', $creating && ! $this->filled('guest_id')));
    }

    public function attributes(): array
    {
        return GuestRules::attributes('guest.') + [
            'rooms.*.check_in' => __('reservations.fields.check_in'), 'rooms.*.check_out' => __('reservations.fields.check_out'),
            'rooms.*.adults' => __('reservations.fields.adults'), 'rooms.*.room_type_id' => __('reservations.fields.room_type'),
            'rooms.*.rate_plan_id' => __('reservations.fields.rate_plan'), 'rooms.*.rate' => __('reservations.fields.rate'),
        ];
    }
}
