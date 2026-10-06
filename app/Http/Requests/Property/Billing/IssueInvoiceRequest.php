<?php

namespace App\Http\Requests\Property\Billing;

use Illuminate\Foundation\Http\FormRequest;

/** Optional recipient details (B2B: company name, GSTIN, address, state). */
class IssueInvoiceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'bill_to_name' => ['nullable', 'string', 'max:190'],
            // GSTIN: 2-digit state code, PAN (5 letters, 4 digits, 1 letter), entity number, Z, check character.
            'bill_to_tax_no' => ['nullable', 'string', 'max:30', 'regex:/^(\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]|[A-Z0-9\-]{5,30})$/i'],
            'bill_to_address' => ['nullable', 'string', 'max:500'],
            'bill_to_state' => ['nullable', 'string', 'max:10'],
        ];
    }
}
