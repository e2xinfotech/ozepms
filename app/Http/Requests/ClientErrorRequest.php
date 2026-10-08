<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Error report sent by the browser (resources/js/lib/errors.ts). */
class ClientErrorRequest extends FormRequest
{
    public function rules(): array
    {
        $max = (int) config('ozepms.logging.client_error_max_chars');

        return [
            'message' => ['required', 'string', 'max:1000'],
            'source' => ['nullable', 'string', 'max:500'],
            'line' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'column' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'stack' => ['nullable', 'string', 'max:'.$max],
            'component' => ['nullable', 'string', 'max:'.$max],
            'url' => ['nullable', 'string', 'max:500'],
            'page' => ['nullable', 'string', 'max:120'],
        ];
    }
}
