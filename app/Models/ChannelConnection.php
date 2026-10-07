<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProperty;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A property's connection to one sales channel (OTA or the built-in Test Channel). */
class ChannelConnection extends Model
{
    use BelongsToProperty, HasPublicId;

    public const STATUSES = ['pending', 'active', 'paused', 'error', 'disconnected'];

    protected $table = 'channel_connections';

    protected $guarded = ['id'];

    protected $hidden = ['id', 'credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array', 'settings' => 'array', 'last_ari_log_id' => 'integer', 'failures' => 'integer',
            'last_success_at' => 'datetime', 'last_error_at' => 'datetime', 'next_attempt_at' => 'datetime', 'last_full_sync_at' => 'datetime',
        ];
    }

    public function roomMappings(): HasMany
    {
        return $this->hasMany(ChannelRoomMapping::class, 'connection_id');
    }

    public function rateMappings(): HasMany
    {
        return $this->hasMany(ChannelRateMapping::class, 'connection_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ChannelSyncLog::class, 'connection_id');
    }

    public function isSyncing(): bool
    {
        return in_array($this->status, ['active', 'error'], true);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return ($this->settings ?? [])[$key] ?? $default;
    }
}
