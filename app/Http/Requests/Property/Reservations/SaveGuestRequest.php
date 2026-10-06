<?php

namespace App\Http\Requests\Property\Reservations;

use App\Models\GuestDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Guest profile create (POST) / update (PUT); tags, notes and document uploads. */
class SaveGuestRequest extends FormRequest
{
    public function rules(): array
    {
        if ($this->routeIs('*.guests.tags')) {
            return ['tags' => ['present', 'array', 'max:12'], 'tags.*' => ['string', 'max:30']];
        }
        if ($this->routeIs('*.guests.notes.store')) {
            return ['body' => ['required', 'string', 'max:2000']];
        }
        if ($this->routeIs('*.guests.documents.store')) {
            return [
                'file' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp,pdf'],
                'type' => ['required', Rule::in(GuestDocument::TYPES)],
                'reservation_id' => ['nullable', 'string', 'size:26'],
            ];
        }

        return GuestRules::rules('', $this->isMethod('POST'));
    }

    public function attributes(): array
    {
        return GuestRules::attributes();
    }
}
