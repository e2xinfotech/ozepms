<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProperty;
use Illuminate\Database\Eloquent\Model;

/**
 * Table: reservation_room_nights. Owned by Phase 4 (see docs/04-development-guide.md).
 * Relationships and behaviour are added by the owning module.
 */
class ReservationRoomNight extends Model
{
    use BelongsToProperty;

    protected $table = 'reservation_room_nights';

    /** Composite primary key (reservation_room_id, stay_date): write through the query builder, not save(). */
    protected $primaryKey = null;

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'stay_date' => 'date',
            'base_price' => 'decimal:2',
            'occupancy_adjust' => 'decimal:2',
            'discount' => 'decimal:2',
            'net_price' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
