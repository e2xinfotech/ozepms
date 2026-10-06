<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProperty;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ARI change (table ari_change_log): what changed, for which dates and by whom. The channel
 * manager reads it as "everything after id X". Written by App\Domain\Inventory\AriJournal.
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
            'date_from' => 'immutable_date',
            'date_to' => 'immutable_date',
            'weekdays' => 'integer',
            'payload' => 'array',
        ];
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class)->withTrashed();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
