<?php

namespace App\Domain\Reservations\Events;

use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Every room of the reservation (or $roomIds only) was checked in.
 * Dispatched after the reservation transaction has committed (listeners see the saved rows).
 */
final class ReservationCheckedIn
{
    use Dispatchable;

    public function __construct(
        public readonly Reservation $reservation,
        public readonly ?User $by = null,
        /** @var list<int> reservation_rooms.id checked in by this action */
        public readonly array $roomIds = [],
    ) {}
}
