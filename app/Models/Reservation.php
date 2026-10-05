<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProperty;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * Table: reservations. Owned by Phase 4 (see docs/04-development-guide.md).
 * Relationships and behaviour are added by the owning module.
 */
class Reservation extends Model
{
    use BelongsToProperty, HasPublicId;

    protected $table = 'reservations';

    protected $guarded = ['id'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'check_in' => 'date',
            'check_out' => 'date',
            'room_total' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'extras_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'paid_total' => 'decimal:2',
            'balance_due' => 'decimal:2',
            'hold_expires_at' => 'date',
            'cancelled_at' => 'date',
            'cancellation_fee' => 'decimal:2',
            'confirmed_at' => 'date',
        ];
    }
}
