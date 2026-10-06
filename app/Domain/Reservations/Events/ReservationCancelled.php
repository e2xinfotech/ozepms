<?php

namespace App\Domain\Reservations\Events;

use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The reservation was cancelled. Inventory is already released. $fee is the cancellation fee
 * computed from the frozen cancellation policy (decimal string, '0.00' when free) — billing posts
 * it as a cancellation_fee folio line, voids the room charges and handles refunds.
 * Also stored on reservations.cancellation_fee.
 * Dispatched after the reservation transaction has committed.
 */
final class ReservationCancelled
{
    use Dispatchable;

    public function __construct(
        public readonly Reservation $reservation,
        public readonly ?User $by = null,
        public readonly string $fee = '0.00',
        public readonly ?string $reason = null,
    ) {}
}
