<?php

namespace App\Domain\Reservations\Events;

use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The guest did not arrive. $fee is the no-show fee from the cancellation policy (decimal string, '0.00' when none); billing posts it.
 * Dispatched after the reservation transaction has committed (listeners see the saved rows).
 */
final class ReservationNoShow
{
    use Dispatchable;

    public function __construct(
        public readonly Reservation $reservation,
        public readonly ?User $by = null,
        public readonly string $fee = '0.00',
    ) {}
}
