<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProperty;
use Illuminate\Database\Eloquent\Model;

/**
 * Table: reservation_rooms. Owned by Phase 4 (see docs/04-development-guide.md).
 * Relationships and behaviour are added by the owning module.
 */
class ReservationRoom extends Model
{
    use BelongsToProperty;

    protected $table = 'reservation_rooms';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'check_in' => 'date',
            'check_out' => 'date',
            'child_ages' => 'array',
            'rate_snapshot' => 'array',
            'room_total' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'checked_in_at' => 'date',
            'checked_out_at' => 'date',
        ];
    }
}
