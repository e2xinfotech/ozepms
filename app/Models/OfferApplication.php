<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProperty;
use Illuminate\Database\Eloquent\Model;

/**
 * Table: offer_applications. Owned by Phase 5 (see docs/04-development-guide.md).
 * Relationships and behaviour are added by the owning module.
 */
class OfferApplication extends Model
{
    use BelongsToProperty;

    protected $table = 'offer_applications';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'discount_amount' => 'decimal:2',
            'snapshot' => 'array',
        ];
    }
}
