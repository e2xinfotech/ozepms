<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProperty;
use Illuminate\Database\Eloquent\Model;

/**
 * Table: ari_change_log. Owned by Phase 3 (see docs/04-development-guide.md).
 * Relationships and behaviour are added by the owning module.
 */
class AriChangeLog extends Model
{
    use BelongsToProperty;

    protected $table = 'ari_change_log';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date_from' => 'date',
            'date_to' => 'date',
            'payload' => 'array',
        ];
    }
}
