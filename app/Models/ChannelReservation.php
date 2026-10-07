<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A booking received from a channel, linked to the PMS reservation it created. */
class ChannelReservation extends Model
{
    protected $table = 'channel_reservations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'version' => 'integer'];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class)->withoutGlobalScopes();
    }
}
