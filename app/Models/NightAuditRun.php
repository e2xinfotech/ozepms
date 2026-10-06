<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProperty;
use Illuminate\Database\Eloquent\Model;

/** One night audit of one property for one business date (unique), with what it did. */
class NightAuditRun extends Model
{
    use BelongsToProperty;

    protected $table = 'night_audit_runs';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'business_date' => 'immutable_date',
            'stats' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
