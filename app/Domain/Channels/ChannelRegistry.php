<?php

namespace App\Domain\Channels;

use App\Domain\Channels\Contracts\ChannelProvider;
use App\Models\ChannelConnection;
use InvalidArgumentException;

/** The channels of config/channels.php and their adapters. */
class ChannelRegistry
{
    /** @return array<string, array{key: string, available: bool}> */
    public function all(): array
    {
        $out = [];
        foreach ((array) config('channels.providers', []) as $key => $p) {
            $out[$key] = ['key' => $key, 'available' => ! empty($p['class']), 'requires_approval' => $this->requiresApproval($key)];
        }

        return $out;
    }

    /** Connections to real channels need approval; E2X enters the credentials. */
    public function requiresApproval(string $key): bool
    {
        return (bool) config("channels.providers.{$key}.requires_approval", true);
    }

    public function available(string $key): bool
    {
        return ! empty(config("channels.providers.{$key}.class"));
    }

    public function provider(string|ChannelConnection $key): ChannelProvider
    {
        $key = $key instanceof ChannelConnection ? $key->provider : $key;
        $class = config("channels.providers.{$key}.class");
        if (! $class) {
            throw new InvalidArgumentException("Channel {$key} is not available.");
        }

        return app($class);
    }
}
