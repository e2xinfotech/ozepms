<?php

namespace App\Domain\Reservations;

use Illuminate\Support\Facades\DB;

/**
 * Gap-free per-property sequences in property_counters (booking references, guest numbers).
 * The counter row stays locked until the surrounding transaction commits, so two bookings
 * never get the same number; callers take the number as late as possible in their transaction.
 */
class ReferenceNumbers
{
    public function next(int $propertyId, string $key): int
    {
        // LAST_INSERT_ID(expr) hands the new value back on this connection without a second lock.
        DB::statement(
            'INSERT INTO property_counters (property_id, counter_key, current_value) VALUES (?, ?, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE current_value = LAST_INSERT_ID(current_value + 1)',
            [$propertyId, $key],
        );

        return (int) DB::selectOne('SELECT LAST_INSERT_ID() AS v')->v;
    }

    /** "R-2026001": year of booking + sequence of that year (3 digits minimum). */
    public function bookingRef(int $propertyId, int $year): string
    {
        $seq = $this->next($propertyId, 'reservation:'.$year);

        return 'R-'.$year.str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }

    public function guestNo(int $propertyId): int
    {
        return $this->next($propertyId, 'guest');
    }
}
