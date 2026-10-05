<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProperty;
use Illuminate\Database\Eloquent\Model;

/**
 * Table: folios. Owned by Phase 4 (see docs/04-development-guide.md).
 * Relationships and behaviour are added by the owning module.
 */
class Folio extends Model
{
    use BelongsToProperty;

    protected $table = 'folios';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'charges_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'payments_total' => 'decimal:2',
            'closed_at' => 'date',
        ];
    }
}
