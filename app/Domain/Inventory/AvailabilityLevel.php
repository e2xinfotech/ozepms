<?php

namespace App\Domain\Inventory;

/**
 * One rule for how full a night is, shared by the calendar colours, the year overview and the
 * availability filter: sold out (nothing left), low (at most low_availability_percent of the
 * rooms left, at least 1 room) or ok.
 */
final class AvailabilityLevel
{
    public const SOLD_OUT = 'sold_out';

    public const LOW = 'low';

    public const OK = 'ok';

    public static function of(int $available, int $total): string
    {
        if ($available <= 0) {
            return self::SOLD_OUT;
        }

        return $available <= self::lowThreshold($total) ? self::LOW : self::OK;
    }

    /** Rooms left at or below which a night counts as low (never below 1). */
    public static function lowThreshold(int $total): int
    {
        $percent = (int) config('ozepms.inventory.low_availability_percent', 20);

        return max(1, intdiv(max(0, $total) * $percent, 100));
    }
}
