<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One message to or from a channel (availability / rates / restrictions push, booking import …). */
class ChannelSyncLog extends Model
{
    protected $table = 'channel_sync_logs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['attempts' => 'integer', 'items' => 'integer'];
    }
}
