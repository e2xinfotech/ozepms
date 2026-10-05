<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Table: booking_sources. Owned by Phase 4 (see docs/04-development-guide.md).
 * Relationships and behaviour are added by the owning module.
 */
class BookingSource extends Model
{
    protected $table = 'booking_sources';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
