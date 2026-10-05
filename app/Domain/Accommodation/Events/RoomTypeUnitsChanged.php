<?php

namespace App\Domain\Accommodation\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched by Phase 2 when PMS rooms of a room type are added, removed, activated or
 * deactivated, or when a room type is created/activated. Phase 3 listens and updates
 * inventory_daily.total_units.
 */
final class RoomTypeUnitsChanged
{
    use Dispatchable;

    public function __construct(public readonly int $propertyId, public readonly int $roomTypeId) {}
}
