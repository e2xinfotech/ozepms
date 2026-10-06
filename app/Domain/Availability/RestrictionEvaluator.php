<?php

namespace App\Domain\Availability;

use Carbon\CarbonImmutable;

/**
 * Checks a stay against daily restrictions (architecture §8). Pure: works on rows already loaded,
 * so the availability search, the calendar and reservations share one set of rules.
 *
 * A restriction row is an array with any of:
 *   stop_sell, cta, ctd (bool) · min_los, max_los, min_los_arrival, cutoff_days, max_advance_days (int|null)
 *
 *   arrival night:  not CTA · MinLOS-on-arrival ≤ nights · cutoff (days before arrival) satisfied ·
 *                   not booked more than max_advance_days ahead
 *   every night:    not stop sell · min_los ≤ nights ≤ max_los
 *   departure day:  not CTD (the row of the check-out date, when there is one)
 */
final class RestrictionEvaluator
{
    /** Reasons, in the order they are reported. */
    public const REASONS = ['closed', 'stop_sell', 'cta', 'ctd', 'min_los', 'max_los', 'min_los_arrival', 'cutoff', 'max_advance', 'no_rate'];

    /**
     * @param  array<string, array<string, mixed>|null>  $rows  restriction row per stay date (Y-m-d) of the
     *                                                           nights and, optionally, the check-out date
     * @param  CarbonImmutable  $bookedOn  the property's today (date of booking)
     * @return list<string> reasons the stay cannot be sold; empty = allowed
     */
    public function evaluate(array $rows, CarbonImmutable $checkIn, CarbonImmutable $checkOut, CarbonImmutable $bookedOn, string $stopSellReason = 'closed'): array
    {
        $nights = (int) $checkIn->diffInDays($checkOut, false);
        if ($nights < 1) {
            return ['min_los'];
        }
        $reasons = [];

        for ($d = $checkIn; $d->lessThan($checkOut); $d = $d->addDay()) {
            $row = $rows[$d->toDateString()] ?? null;
            if ($row === null) {
                $reasons['no_rate'] = true;

                continue;
            }
            if (! empty($row['stop_sell'])) {
                $reasons[$stopSellReason] = true;
            }
            if (($row['min_los'] ?? 0) > 0 && $nights < (int) $row['min_los']) {
                $reasons['min_los'] = true;
            }
            if (($row['max_los'] ?? 0) > 0 && $nights > (int) $row['max_los']) {
                $reasons['max_los'] = true;
            }
        }

        $arrival = $rows[$checkIn->toDateString()] ?? null;
        if ($arrival !== null) {
            if (! empty($arrival['cta'])) {
                $reasons['cta'] = true;
            }
            if (($arrival['min_los_arrival'] ?? 0) > 0 && $nights < (int) $arrival['min_los_arrival']) {
                $reasons['min_los_arrival'] = true;
            }
            $lead = (int) $bookedOn->startOfDay()->diffInDays($checkIn->startOfDay(), false);
            if (($arrival['cutoff_days'] ?? 0) > 0 && $lead < (int) $arrival['cutoff_days']) {
                $reasons['cutoff'] = true;
            }
            if (($arrival['max_advance_days'] ?? 0) > 0 && $lead > (int) $arrival['max_advance_days']) {
                $reasons['max_advance'] = true;
            }
        }

        $departure = $rows[$checkOut->toDateString()] ?? null;
        if ($departure !== null && ! empty($departure['ctd'])) {
            $reasons['ctd'] = true;
        }

        return array_values(array_filter(self::REASONS, fn ($r) => isset($reasons[$r])));
    }

    /**
     * Restriction row that applies to a product on one date. Derived products that inherit
     * restrictions take the parent's row (recursively), but keep their own stop sell as well:
     * closing one derived rate plan never needs the parent to close.
     *
     * @param  array<string, mixed>|null  $own
     * @param  array<string, mixed>|null  $parent  already resolved for the parent
     */
    public static function effective(?array $own, ?array $parent, bool $inherits): ?array
    {
        if (! $inherits) {
            return $own;
        }
        if ($parent === null) {
            return $own;
        }
        $row = $parent;
        $row['stop_sell'] = ! empty($parent['stop_sell']) || ! empty($own['stop_sell']);

        return $row;
    }
}
