<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProperty;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Table: offers. Owned by Phase 5 (see docs/04-development-guide.md).
 * Relationships and behaviour are added by the owning module.
 */
class Offer extends Model
{
    use BelongsToProperty, HasPublicId, SoftDeletes;

    protected $table = 'offers';

    protected $guarded = ['id'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:4',
            'min_amount' => 'decimal:2',
            'booking_from' => 'date',
            'booking_to' => 'date',
            'stay_from' => 'date',
            'stay_to' => 'date',
            'is_stackable' => 'boolean',
            'on_pms' => 'boolean',
            'on_booking_engine' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
