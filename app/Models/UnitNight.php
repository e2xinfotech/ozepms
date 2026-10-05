<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProperty;
use Illuminate\Database\Eloquent\Model;

/**
 * Table: unit_nights. Owned by Phase 4 (see docs/04-development-guide.md).
 * Relationships and behaviour are added by the owning module.
 */
class UnitNight extends Model
{
    use BelongsToProperty;

    protected $table = 'unit_nights';

    /** Composite primary key (unit_id, stay_date): write through the query builder, not save(). */
    protected $primaryKey = null;

    public $incrementing = false;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'stay_date' => 'date',
        ];
    }
}
