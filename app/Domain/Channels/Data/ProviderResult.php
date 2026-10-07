<?php

namespace App\Domain\Channels\Data;

/** Outcome of a call to a channel. retryable = a temporary problem (network, rate limit, 5xx). */
final class ProviderResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $message = null,
        public readonly ?string $request = null,
        public readonly ?string $response = null,
        public readonly bool $retryable = true,
    ) {}

    public static function ok(?string $message = null, ?string $request = null, ?string $response = null): self
    {
        return new self(true, $message, $request, $response);
    }

    public static function fail(string $message, bool $retryable = true, ?string $request = null, ?string $response = null): self
    {
        return new self(false, $message, $request, $response, $retryable);
    }
}
