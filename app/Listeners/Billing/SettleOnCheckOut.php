<?php

namespace App\Listeners\Billing;

use App\Domain\Billing\FolioService;
use App\Domain\Billing\InvoiceService;
use App\Domain\Reservations\Events\ReservationCheckedOut;
use Throwable;

/**
 * Check-out: every remaining night of the rooms that left is posted; when the whole reservation
 * has left, the tax invoice is issued (config ozepms.billing.invoice_on_checkout) and a settled
 * folio is closed.
 */
class SettleOnCheckOut
{
    public function __construct(
        private readonly FolioService $folios,
        private readonly InvoiceService $invoices,
    ) {}

    public function handle(ReservationCheckedOut $event): void
    {
        $reservation = $event->reservation;
        try {
            $this->folios->postRoomNights($reservation, null, $event->by, $event->roomIds !== [] ? array_map('intval', $event->roomIds) : null);
            if ($reservation->status !== 'checked_out') {
                return;
            }
            $folio = $this->folios->open($reservation);
            if (config('ozepms.billing.invoice_on_checkout') && $this->invoices->hasBillableLines($folio)) {
                $this->invoices->issue($folio, [], $event->by);
            }
            $this->folios->closeIfSettled($reservation);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
