<?php

namespace App\Http\Requests\Property\Channels;

use Illuminate\Foundation\Http\FormRequest;

/** Add or edit a channel connection (credential values depend on the channel and are checked by the connection service). */
class ConnectionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'provider' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:30'],
            'name' => ['nullable', 'string', 'max:80'],
            'external_hotel_id' => ['required', 'string', 'max:64'],
            'credentials' => ['nullable', 'array', 'max:20'],
            'credentials.*' => ['nullable', 'string', 'max:500'],
        ];
    }
}
