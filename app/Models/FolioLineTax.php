<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One tax component (CGST, SGST, IGST, VAT…) of a folio line, frozen at posting time. */
class FolioLineTax extends Model
{
    protected $table = 'folio_line_taxes';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
            'taxable_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
        ];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(FolioLine::class, 'folio_line_id');
    }
}
