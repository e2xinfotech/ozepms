<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Table: reservation_status_history. Owned by Phase 4 (see docs/04-development-guide.md).
 * Relationships and behaviour are added by the owning module.
 */
class ReservationStatusHistory extends Model
{
    protected $table = 'reservation_status_history';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];
}
