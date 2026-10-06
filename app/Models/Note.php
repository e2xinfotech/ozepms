<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProperty;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Staff note on a reservation or a guest (append-only). */
class Note extends Model
{
    use BelongsToProperty;

    public const UPDATED_AT = null;

    protected $table = 'notes';

    protected $guarded = ['id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
