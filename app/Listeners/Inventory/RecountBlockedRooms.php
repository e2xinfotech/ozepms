<?php

namespace App\Listeners\Inventory;

use App\Domain\Accommodation\Events\UnitBlockChanged;
use App\Domain\Inventory\Exceptions\NotAvailableException;
use App\Domain\Inventory\InventoryService;
use Illuminate\Validation\ValidationException;

/**
 * A PMS room was blocked (out of order / maintenance / owner hold) or released: rooms out of
 * sale per night are recounted for the affected dates. A block that would leave fewer rooms
 * than are sold is refused (and the block rolled back).
 */
class RecountBlockedRooms
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function handle(UnitBlockChanged $event): void
    {
        try {
            $this->inventory->ensureRoomTypeRows($event->roomTypeId, $event->from, $event->to);
            $this->inventory->recountBlocked($event->roomTypeId, $event->from, $event->to);
        } catch (NotAvailableException $e) {
            throw ValidationException::withMessages(['start_date' => $e->getMessage()]);
        }
    }
}
