<?php

namespace App\Domain\Inventory;

/**
 * Outcome of AriService::apply().
 *
 *   inventoryRows  inventory_daily rows changed (room-type level fields)
 *   ariRows        ari_daily rows changed (product price / restrictions)
 *   occupancyRows  ari_daily_occupancy rows written or removed
 *   skipped        targets (or parts of targets) that were not changed, each
 *                  ['type' => 'room_type'|'product', 'id' => int, 'field' => string|null, 'reason' => string, 'dates' => int]
 *                  reasons: derived_price (derived products compute their price), inherits_restrictions
 *                  (derived product follows its parent), past (dates before the property's today),
 *                  beyond_horizon, sell_limit_too_high (limit above total rooms, capped), not_found (wrong property / missing)
 *   ariVersion     properties.ari_version after the change (unchanged when nothing changed)
 */
final class AriApplyResult
{
    /** @param  list<array{type: string, id: int, field: ?string, reason: string, dates: int}>  $skipped */
    public function __construct(
        public readonly int $inventoryRows,
        public readonly int $ariRows,
        public readonly int $occupancyRows,
        public readonly array $skipped,
        public readonly int $ariVersion,
    ) {}

    public function changed(): bool
    {
        return $this->inventoryRows + $this->ariRows + $this->occupancyRows > 0;
    }

    public function toArray(): array
    {
        return [
            'inventory_rows' => $this->inventoryRows,
            'ari_rows' => $this->ariRows,
            'occupancy_rows' => $this->occupancyRows,
            'skipped' => $this->skipped,
            'ari_version' => $this->ariVersion,
        ];
    }
}
