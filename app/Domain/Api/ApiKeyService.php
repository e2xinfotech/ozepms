<?php

namespace App\Domain\Api;

use App\Domain\Accommodation\InProperty;
use App\Domain\Audit\AuditLogger;
use App\Models\ApiKey;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Property API keys. Format: ozk_{prefix 12}_{secret 40}. The full key is returned once at
 * creation; the database keeps the prefix (lookup) and the SHA-256 hash of the whole key.
 */
class ApiKeyService
{
    public const MAX_KEYS = 20;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array{0: ApiKey, 1: string} the key and its one-time plain value */
    public function create(Property $property, string $name, array $abilities, ?User $by = null, ?\DateTimeInterface $expiresAt = null): array
    {
        $abilities = array_values(array_unique(array_intersect($abilities, ApiKey::ABILITIES)));
        if ($abilities === []) {
            throw ValidationException::withMessages(['abilities' => __('property.api_keys.errors.abilities')]);
        }
        if (ApiKey::acrossProperties()->where('property_id', $property->id)->whereNull('revoked_at')->count() >= self::MAX_KEYS) {
            throw ValidationException::withMessages(['name' => __('property.api_keys.errors.limit', ['max' => self::MAX_KEYS])]);
        }
        $prefix = Str::lower(Str::random(12));
        $plain = 'ozk_'.$prefix.'_'.Str::random(40);
        $key = InProperty::run($property, fn () => ApiKey::query()->create([
            'property_id' => $property->id, 'name' => mb_substr(trim($name), 0, 80), 'prefix' => $prefix,
            'key_hash' => hash('sha256', $plain), 'abilities' => $abilities, 'expires_at' => $expiresAt, 'created_by' => $by?->id,
        ]));
        $this->audit->log('api_key.created', $key, ['after' => ['name' => $key->name, 'abilities' => $abilities, 'prefix' => $prefix]], $property->id, $by?->id);

        return [$key, $plain];
    }

    public function revoke(ApiKey $key, ?User $by = null): void
    {
        if ($key->revoked_at === null) {
            ApiKey::acrossProperties()->whereKey($key->id)->update(['revoked_at' => now()]);
            $key->revoked_at = now();
            $this->audit->log('api_key.revoked', $key, ['before' => ['name' => $key->name, 'prefix' => $key->prefix]], $key->property_id, $by?->id);
        }
    }

    /** The usable key behind a presented value, or null (constant-time hash comparison). */
    public function verify(string $plain): ?ApiKey
    {
        if (! preg_match('/^ozk_([a-z0-9]{12})_[A-Za-z0-9]{40}$/', $plain, $m)) {
            return null;
        }
        $key = ApiKey::acrossProperties()->where('prefix', $m[1])->first();
        if ($key === null || ! hash_equals($key->key_hash, hash('sha256', $plain)) || ! $key->isUsable()) {
            return null;
        }

        return $key;
    }
}
