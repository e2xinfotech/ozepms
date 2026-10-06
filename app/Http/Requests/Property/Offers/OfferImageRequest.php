<?php

namespace App\Http\Requests\Property\Offers;

use Illuminate\Foundation\Http\FormRequest;

class OfferImageRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'image' => ['required', 'file', 'image', 'mimes:'.implode(',', config('ozepms.uploads.image_mimes')), 'max:'.config('ozepms.uploads.max_image_kb'), 'dimensions:min_width=200,min_height=150'],
        ];
    }
}
