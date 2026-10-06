<?php

namespace App\Http\Requests\Property;

use Illuminate\Foundation\Http\FormRequest;

/** Property logo or cover photo: real images only (content checked), limited size. */
class UploadPropertyMediaRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'image' => ['required', 'file', 'image', 'mimes:'.implode(',', config('ozepms.uploads.image_mimes')), 'max:'.config('ozepms.uploads.max_image_kb'), 'dimensions:min_width=64,min_height=64,max_width=8000,max_height=8000'],
        ];
    }
}
