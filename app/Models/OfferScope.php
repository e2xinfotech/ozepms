<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Table: offer_scopes. Owned by Phase 5 (see docs/04-development-guide.md).
 * Relationships and behaviour are added by the owning module.
 */
class OfferScope extends Model
{
    protected $table = 'offer_scopes';

    public $timestamps = false;

    protected $guarded = ['id'];
}
