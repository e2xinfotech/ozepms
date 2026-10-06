<?php

namespace App\Domain\Billing;

use App\Models\Property;
use Carbon\CarbonImmutable;

/**
 * The property's operational day. properties.business_date is moved forward by the night audit;
 * until the audit of a day has run, charges are still posted on that day. Without an audit yet
 * (or when it is ahead), the local calendar date in the property timezone is used.
 */
final class BusinessDate
{
    public static function localToday(Property $property): CarbonImmutable
    {
        return CarbonImmutable::parse(now($property->timezone ?: config('app.timezone'))->toDateString());
    }

    public static function of(Property $property): CarbonImmutable
    {
        $local = self::localToday($property);
        $business = $property->business_date ? CarbonImmutable::parse($property->business_date->toDateString()) : null;

        return $business !== null && $business->lessThan($local) ? $business : $local;
    }

    /** The day the next night audit closes: the stored business date, or yesterday before the first audit. */
    public static function open(Property $property): CarbonImmutable
    {
        return $property->business_date
            ? CarbonImmutable::parse($property->business_date->toDateString())
            : self::localToday($property)->subDay();
    }
}
