<?php

namespace App\Domain\Channels\Data;

/**
 * One availability / rate / restriction update for a run of dates [from, to] (inclusive) with the
 * same values. Room-level updates have no rate id and carry availability (and the room's stop sell);
 * rate-level updates carry price and restrictions. Null = not part of this update.
 */
final class AriUpdate
{
    public function __construct(
        public readonly string $roomId,
        public readonly ?string $rateId,
        public readonly string $from,
        public readonly string $to,
        public readonly ?int $availability = null,
        public readonly ?string $price = null,
        public readonly ?int $minLos = null,
        public readonly ?int $maxLos = null,
        public readonly ?bool $cta = null,
        public readonly ?bool $ctd = null,
        public readonly ?bool $stopSell = null,
    ) {}

    /** Values without the dates (used to merge consecutive days). */
    public function values(): array
    {
        return ['availability' => $this->availability, 'price' => $this->price, 'min_los' => $this->minLos, 'max_los' => $this->maxLos,
            'cta' => $this->cta, 'ctd' => $this->ctd, 'stop_sell' => $this->stopSell];
    }

    public function toArray(): array
    {
        return ['room_id' => $this->roomId, 'rate_id' => $this->rateId, 'from' => $this->from, 'to' => $this->to] + array_filter($this->values(), fn ($v) => $v !== null);
    }
}
