<?php

namespace App\Http\Requests\Property\Reservations;

use Illuminate\Foundation\Http\FormRequest;

/** Cancel / no-show / check-in / check-out / assign / note: small action payloads. */
class ActionRequest extends FormRequest
{
    public function rules(): array
    {
        return array_merge([
            'reason' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
            'waive_fee' => ['nullable', 'boolean'],
            'override_balance' => ['nullable', 'boolean'],
            'rooms' => ['nullable', 'array', 'max:10'],
            'rooms.*' => ['string', 'size:26'],
            'room_id' => [$this->routeIs('*.assign') ? 'required' : 'nullable', 'string', 'size:26'],
            'unit_id' => ['nullable', 'string', 'size:26'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after:from'],
            'body' => [$this->routeIs('*.notes.store') ? 'required' : 'nullable', 'string', 'max:2000'],
            'guest' => ['nullable', 'array', 'max:30'],
        ], GuestRules::rules('guest.', false));
    }
}
