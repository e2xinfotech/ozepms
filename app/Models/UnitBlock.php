<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProperty;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Out-of-order / maintenance period of a PMS room. end_date is exclusive. */
class UnitBlock extends Model
{
    use BelongsToProperty;

    public const TYPES = ['out_of_order', 'maintenance', 'owner_hold'];

    protected $table = 'unit_blocks';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'released_at' => 'datetime',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(PhysicalUnit::class, 'unit_id')->withTrashed();
    }

    /** Blocks that are not released and cover at least one night of [from, to). */
    public function scopeOverlapping(Builder $query, string $from, string $to): Builder
    {
        return $query->whereNull('released_at')->where('start_date', '<', $to)->where('end_date', '>', $from);
    }
}
