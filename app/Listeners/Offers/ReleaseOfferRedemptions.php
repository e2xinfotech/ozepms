<?php

namespace App\Listeners\Offers;

use App\Domain\Offers\OfferService;
use App\Domain\Reservations\Events\ReservationCancelled;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Cancelled booking: its offers count one redemption less (the frozen applications stay as history). */
class ReleaseOfferRedemptions
{
    public function __construct(private readonly OfferService $offers) {}

    public function handle(ReservationCancelled $event): void
    {
        try {
            $ids = DB::table('offer_applications')->where('reservation_id', $event->reservation->id)->distinct()->pluck('offer_id')->map(fn ($id) => (int) $id)->all();
            $this->offers->release($ids);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
