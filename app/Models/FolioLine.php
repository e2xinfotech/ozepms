<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProperty;
use Illuminate\Database\Eloquent\Model;

/**
 * Table: folio_lines. Owned by Phase 4 (see docs/04-development-guide.md).
 * Relationships and behaviour are added by the owning module.
 */
class FolioLine extends Model
{
    use BelongsToProperty;

    protected $table = 'folio_lines';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:4',
            'amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'is_void' => 'boolean',
        ];
    }
}
