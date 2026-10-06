<?php

namespace App\Domain\Reservations\Events;

use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dates, rooms, rates or guests of a reservation changed. Billing re-posts room charges when
 * 'dates', 'rooms' or 'rates' is present.
 *
 * $changes keys (only those that changed):
 *   'dates'  => ['from' => ['check_in' => 'Y-m-d', 'check_out' => 'Y-m-d'], 'to' => [...]]
 *   'rooms'  => ['added' => [reservation_rooms.id...], 'removed' => [...], 'moved' => [[room_id, from_unit_id, to_unit_id]...]]
 *   'rates'  => ['from' => old grand_total, 'to' => new grand_total]   (decimal strings)
 *   'guests' => ['adults' => [old, new], 'children' => [old, new], 'primary_guest' => [old id, new id]]
 *   'details' => [field names]   (notes, special requests, source …; no billing impact)
 * Dispatched after the reservation transaction has committed.
 */
final class ReservationModified
{
    use Dispatchable;

    public function __construct(
        public readonly Reservation $reservation,
        public readonly ?User $by = null,
        /** @var array<string, mixed> */
        public readonly array $changes = [],
    ) {}

    public function affectsCharges(): bool
    {
        return isset($this->changes['dates']) || isset($this->changes['rooms']['added']) || isset($this->changes['rooms']['removed']) || isset($this->changes['rates']);
    }
}
