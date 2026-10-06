<?php

namespace App\Listeners\Billing;

use App\Domain\Billing\FolioService;
use App\Domain\Reservations\Events\ReservationCancelled;
use Throwable;

/** Cancelled: room charges are voided and the policy's cancellation fee is posted. */
class PostCancellationFee
{
    public function __construct(private readonly FolioService $folios) {}

    public function handle(ReservationCancelled $event): void
    {
        try {
            $this->folios->postFee($event->reservation, $event->fee, 'cancellation', $event->by);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
