<?php

namespace App\Domain\Inventory;

use Carbon\CarbonImmutable;

/** Stay dates as Y-m-d strings. Date lists are reused because searches walk the same stay many times. */
final class StayDates
{
    /** @var array<string, list<string>> */
    private static array $cache = [];

    /** @return list<string> nights of [checkIn, checkOut) */
    public static function nights(CarbonImmutable $checkIn, CarbonImmutable $checkOut): array
    {
        $key = $checkIn->toDateString().'|'.$checkOut->toDateString();
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        if (count(self::$cache) > 500) {
            self::$cache = [];
        }
        $dates = [];
        for ($d = $checkIn->startOfDay(), $end = $checkOut->startOfDay(); $d->lessThan($end); $d = $d->addDay()) {
            $dates[] = $d->toDateString();
        }

        return self::$cache[$key] = $dates;
    }
}
