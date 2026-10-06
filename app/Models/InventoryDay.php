<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProperty;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rooms of one room type on one night (table inventory_daily). Read model only: all writes go
 * through App\Domain\Inventory\InventoryService / AriService (guarded updates).
 */
class InventoryDay extends Model
{
    use BelongsToProperty;

    protected $table = 'inventory_daily';

    /** Composite primary key (room_type_id, stay_date): write through the query builder, not save(). */
    protected $primaryKey = null;

    public $incrementing = false;

    public const CREATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'stay_date' => 'immutable_date',
            'total_units' => 'integer',
            'ooo_units' => 'integer',
            'sold' => 'integer',
            'held' => 'integer',
            'sell_limit' => 'integer',
            'stop_sell' => 'boolean',
        ];
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class)->withTrashed();
    }

    /** Rooms that can still be sold on this night. */
    public function remaining(): int
    {
        $capacity = $this->sell_limit === null ? $this->total_units : min($this->total_units, $this->sell_limit);

        return max(0, $capacity - $this->ooo_units - $this->sold - $this->held);
    }
}
