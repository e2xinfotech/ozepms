<?php

namespace App\Listeners\Billing;

use App\Domain\Billing\FolioService;
use App\Domain\Reservations\Events\ReservationCreated;
use Throwable;

/** A new booking gets its folio; its room nights count as pending charges until posted. */
class OpenFolio
{
    public function __construct(private readonly FolioService $folios) {}

    public function handle(ReservationCreated $event): void
    {
        try {
            $this->folios->open($event->reservation);
            $this->folios->refresh($event->reservation);
        } catch (Throwable $e) {
            // The booking is saved; billing totals are recalculated on the next folio change.
            report($e);
        }
    }
}
