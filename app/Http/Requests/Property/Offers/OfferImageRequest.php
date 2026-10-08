<?php

namespace App\Http\Requests\Property\Offers;

use Illuminate\Foundation\Http\FormRequest;

class OfferImageRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'image' => ['required', 'file', 'image', 'mimes:'.implode(',', config('ozepms.uploads.image_mimes')), 'max:'.config('ozepms.uploads.max_image_kb'), 'mimetypes:image/jpeg,image/png,image/webp', 'dimensions:min_width=200,min_height=150,max_width=8000,max_height=8000'],
        ];
    }
}
