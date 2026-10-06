<?php

namespace App\Domain\Reservations\Events;

use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A reservation was booked (status confirmed / pending). Billing opens the folio and posts room charges.
 * Dispatched after the reservation transaction has committed (listeners see the saved rows).
 */
final class ReservationCreated
{
    use Dispatchable;

    public function __construct(
        public readonly Reservation $reservation,
        public readonly ?User $by = null,
    ) {}
}
