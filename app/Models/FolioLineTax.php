<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Table: folio_line_taxes. Owned by Phase 4 (see docs/04-development-guide.md).
 * Relationships and behaviour are added by the owning module.
 */
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
}
