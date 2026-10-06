<?php

namespace App\Listeners\Inventory;

use App\Domain\Accommodation\Events\RoomTypeUnitsChanged;
use App\Domain\Inventory\Exceptions\NotAvailableException;
use App\Domain\Inventory\InventoryService;
use Illuminate\Validation\ValidationException;

/**
 * PMS rooms added, removed, (de)activated or a room type created: total rooms per night follow
 * the number of active rooms. Runs inside the caller's transaction, so a refusal rolls the
 * room change back.
 */
class SyncRoomTypeInventory
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function handle(RoomTypeUnitsChanged $event): void
    {
        try {
            $this->inventory->syncUnitCount($event->roomTypeId);
        } catch (NotAvailableException $e) {
            throw ValidationException::withMessages(['units' => $e->getMessage()]);
        }
    }
}
