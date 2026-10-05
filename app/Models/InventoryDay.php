<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProperty;
use Illuminate\Database\Eloquent\Model;

/**
 * Table: inventory_daily. Owned by Phase 3 (see docs/04-development-guide.md).
 * Relationships and behaviour are added by the owning module.
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
            'stay_date' => 'date',
            'stop_sell' => 'boolean',
        ];
    }
}
