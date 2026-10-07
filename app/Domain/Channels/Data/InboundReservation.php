<?php

namespace App\Domain\Channels\Data;

/**
 * A booking message from a channel, in the channel manager's own format.
 *
 *   type     new | modify | cancel
 *   rooms    [['room_id' => channel room, 'rate_id' => ?channel rate, 'check_in' => Y-m-d, 'check_out' => Y-m-d,
 *              'adults' => int, 'children' => int, 'infants' => int, 'nightly' => [Y-m-d => price]]]
 *   guest    ['first_name', 'last_name', 'email', 'phone', 'country']
 */
final class InboundReservation
{
    public function __construct(
        public readonly string $type,
        public readonly string $externalRef,
        public readonly int $version,
        public readonly array $guest = [],
        public readonly array $rooms = [],
        public readonly ?string $currency = null,
        public readonly ?string $total = null,
        public readonly ?string $notes = null,
        public readonly array $raw = [],
    ) {}

    public static function fromArray(array $a): self
    {
        return new self(
            type: (string) ($a['type'] ?? 'new'),
            externalRef: (string) ($a['external_ref'] ?? ''),
            version: (int) ($a['version'] ?? 1),
            guest: (array) ($a['guest'] ?? []),
            rooms: array_values((array) ($a['rooms'] ?? [])),
            currency: isset($a['currency']) ? strtoupper((string) $a['currency']) : null,
            total: isset($a['total']) ? (string) $a['total'] : null,
            notes: isset($a['notes']) ? (string) $a['notes'] : null,
            raw: $a,
        );
    }

    public function toArray(): array
    {
        return ['type' => $this->type, 'external_ref' => $this->externalRef, 'version' => $this->version, 'guest' => $this->guest,
            'rooms' => $this->rooms, 'currency' => $this->currency, 'total' => $this->total, 'notes' => $this->notes];
    }
}
