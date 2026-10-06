<?php

namespace App\Domain\Inventory;

use App\Domain\Inventory\Exceptions\NotAvailableException;
use App\Models\Property;
use Carbon\CarbonImmutable;

/**
 * Daily room-type inventory (inventory_daily): the single source of truth for how many rooms
 * can still be sold. Stay ranges are [from, to): $to is the check-out date (exclusive).
 */
class InventoryService
{
    /**
     * Takes $rooms rooms of the room type for every night of [from, to). Call inside the caller's
     * transaction. Uses one guarded UPDATE (architecture §9); when fewer rows than nights are
     * updated the transaction must roll back. $hold = true increments `held` (booking-engine hold)
     * instead of `sold`.
     *
     * @throws NotAvailableException with the dates that had no room left
     */
    public function reserve(int $roomTypeId, CarbonImmutable $from, CarbonImmutable $to, int $rooms = 1, bool $hold = false): void
    {
        throw new \LogicException('Not implemented yet.');
    }

    /** Gives back rooms taken by reserve() (cancellation, shortened stay, expired hold). Never goes below zero. */
    public function release(int $roomTypeId, CarbonImmutable $from, CarbonImmutable $to, int $rooms = 1, bool $hold = false): void
    {
        throw new \LogicException('Not implemented yet.');
    }

    /** Turns a hold into a sale for every night (booking paid): held − rooms, sold + rooms. */
    public function confirmHold(int $roomTypeId, CarbonImmutable $from, CarbonImmutable $to, int $rooms = 1): void
    {
        throw new \LogicException('Not implemented yet.');
    }

    /**
     * Sets total_units = number of active PMS rooms of the room type, from the property's today on
     * (past nights keep their history). Refuses (NotAvailableException) when the new total would be
     * below the rooms already sold / held / out of order on some night.
     */
    public function syncUnitCount(int $roomTypeId): void
    {
        throw new \LogicException('Not implemented yet.');
    }

    /**
     * Makes sure inventory_daily rows exist for every active room type and ari_daily rows for every
     * active product from the property's today up to today + $days (default config
     * ozepms.inventory.horizon_days). Existing rows are never changed. Returns rows created.
     */
    public function ensureHorizon(Property $property, ?int $days = null): int
    {
        throw new \LogicException('Not implemented yet.');
    }

    /**
     * Takes $units rooms out of sale for [from, to) (out of order / out of service): ooo_units + units.
     * Guarded like reserve(); throws NotAvailableException when the rooms are already sold.
     */
    public function blockUnits(int $roomTypeId, CarbonImmutable $from, CarbonImmutable $to, int $units = 1): void
    {
        throw new \LogicException('Not implemented yet.');
    }

    /** Puts $units rooms back on sale for [from, to): ooo_units − units (never below zero). */
    public function unblockUnits(int $roomTypeId, CarbonImmutable $from, CarbonImmutable $to, int $units = 1): void
    {
        throw new \LogicException('Not implemented yet.');
    }

    /**
     * Recounts ooo_units for [from, to) from the open unit_blocks of the room type's active rooms
     * (out_of_order, maintenance, owner_hold). Used by the UnitBlockChanged listener.
     *
     * @throws NotAvailableException when the blocked rooms are needed by sold / held rooms
     */
    public function recountBlocked(int $roomTypeId, CarbonImmutable $from, CarbonImmutable $to): void
    {
        throw new \LogicException('Not implemented yet.');
    }
}
