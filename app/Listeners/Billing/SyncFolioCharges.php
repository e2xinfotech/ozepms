<?php

namespace App\Listeners\Billing;

use App\Domain\Billing\FolioService;
use App\Domain\Reservations\Events\ReservationModified;
use Throwable;

/** Dates, rooms or rates changed: posted nights that left the stay are voided, totals follow. */
class SyncFolioCharges
{
    public function __construct(private readonly FolioService $folios) {}

    public function handle(ReservationModified $event): void
    {
        try {
            $this->folios->syncRoomCharges($event->reservation, $event->by);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
