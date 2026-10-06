<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProperty;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/** Property API key (/api/v1). Only the hash of the key is stored. */
class ApiKey extends Model
{
    use BelongsToProperty, HasPublicId;

    public const ABILITIES = ['availability', 'reservations.create', 'reservations.read'];

    protected $table = 'api_keys';

    protected $guarded = ['id'];

    protected $hidden = ['id', 'key_hash'];

    protected function casts(): array
    {
        return ['abilities' => 'array', 'last_used_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function can(string $ability): bool
    {
        return in_array($ability, $this->abilities ?? [], true);
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
