<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProperty;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One cleaning job for a room, opened at check-out and closed when the room is marked clean. */
class HousekeepingTask extends Model
{
    use BelongsToProperty, HasPublicId;

    protected $table = 'housekeeping_tasks';

    protected $guarded = ['id'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return ['notified_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(PhysicalUnit::class, 'unit_id')->withTrashed();
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(HousekeepingStaff::class, 'staff_id')->withTrashed();
    }
}
