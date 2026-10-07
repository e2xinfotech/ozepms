<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** PMS room type ↔ room on the channel. */
class ChannelRoomMapping extends Model
{
    protected $table = 'channel_room_mappings';

    public $timestamps = false;

    protected $guarded = ['id'];

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class)->withoutGlobalScopes();
    }
}
