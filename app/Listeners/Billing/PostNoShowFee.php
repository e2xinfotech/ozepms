<?php

namespace App\Listeners\Billing;

use App\Domain\Billing\FolioService;
use App\Domain\Reservations\Events\ReservationNoShow;
use Throwable;

/** No-show: room charges are voided and the policy's no-show fee is posted. */
class PostNoShowFee
{
    public function __construct(private readonly FolioService $folios) {}

    public function handle(ReservationNoShow $event): void
    {
        try {
            $this->folios->postFee($event->reservation, $event->fee, 'no_show', $event->by);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
