<?php

namespace App\Domain\Accommodation\Events;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched by Phase 2 when an out-of-order / maintenance block is created, changed or released.
 * Phase 3 recalculates inventory_daily.ooo_units for the affected dates. $to is exclusive.
 */
final class UnitBlockChanged
{
    use Dispatchable;

    public function __construct(
        public readonly int $propertyId,
        public readonly int $roomTypeId,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
    ) {}
}
