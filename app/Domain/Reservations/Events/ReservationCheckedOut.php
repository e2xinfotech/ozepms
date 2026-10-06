<?php

namespace App\Domain\Reservations\Events;

use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Rooms were checked out ($roomIds; the reservation is checked_out when all rooms are). Billing may close the folio.
 * Dispatched after the reservation transaction has committed (listeners see the saved rows).
 */
final class ReservationCheckedOut
{
    use Dispatchable;

    public function __construct(
        public readonly Reservation $reservation,
        public readonly ?User $by = null,
        /** @var list<int> reservation_rooms.id checked out by this action */
        public readonly array $roomIds = [],
    ) {}
}
