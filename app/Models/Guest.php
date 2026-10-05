<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProperty;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * Table: guests. Owned by Phase 4 (see docs/04-development-guide.md).
 * Relationships and behaviour are added by the owning module.
 */
class Guest extends Model
{
    use BelongsToProperty, HasPublicId;

    protected $table = 'guests';

    protected $guarded = ['id'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'id_expiry' => 'date',
            'is_vip' => 'boolean',
            'marketing_consent' => 'boolean',
            'anonymized_at' => 'date',
            'id_number_enc' => 'encrypted',
        ];
    }
}
