<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Table: offer_conditions. Owned by Phase 5 (see docs/04-development-guide.md).
 * Relationships and behaviour are added by the owning module.
 */
class OfferCondition extends Model
{
    protected $table = 'offer_conditions';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }
}
