<?php

namespace App\Domain\Availability;

use App\Models\Property;
use Carbon\CarbonImmutable;

/** The one availability engine for PMS, booking engine, channels and reports (architecture §8). */
class AvailabilityService
{
    /**
     * Every active room type of the property with its rooms left for [checkIn, checkOut) and,
     * per active product, whether it can be sold (with reasons) and its price (Quote).
     * Two indexed queries (inventory_daily, ari_daily + ari_daily_occupancy); the rest in memory.
     * $channel: pms | booking_engine | channel (rate plan sell_on_* flags).
     */
    public function search(Property $property, CarbonImmutable $checkIn, CarbonImmutable $checkOut, int $adults, int $children, int $infants, string $channel = 'pms'): AvailabilityResult
    {
        throw new \LogicException('Not implemented yet.');
    }
}
