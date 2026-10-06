<?php

namespace App\Domain\Reports;

/**
 * The reports of a property. "filters" lists the controls the page shows; "range" is the default
 * period: past (last 30 days), future (next 30 days), month (this month) or day (today).
 * Labels and descriptions: lang/{locale}/reports.php → reports.{key}.title / .description.
 */
final class ReportCatalog
{
    public const REPORTS = [
        'overview' => ['icon' => 'chart-column', 'group' => 'performance', 'filters' => ['range', 'group', 'room_type'], 'range' => 'month'],
        'daily' => ['icon' => 'clipboard-check', 'group' => 'operations', 'filters' => ['date'], 'range' => 'day'],
        'occupancy' => ['icon' => 'bed-double', 'group' => 'performance', 'filters' => ['range', 'group', 'room_type'], 'range' => 'month'],
        'revenue' => ['icon' => 'wallet', 'group' => 'finance', 'filters' => ['range', 'group'], 'range' => 'month'],
        'room_types' => ['icon' => 'bed', 'group' => 'performance', 'filters' => ['range'], 'range' => 'month'],
        'rate_plans' => ['icon' => 'tags', 'group' => 'performance', 'filters' => ['range', 'room_type'], 'range' => 'month'],
        'sources' => ['icon' => 'network', 'group' => 'performance', 'filters' => ['range', 'room_type'], 'range' => 'month'],
        'reservations' => ['icon' => 'calendar-check', 'group' => 'bookings', 'filters' => ['range', 'basis', 'status', 'source'], 'range' => 'past'],
        'cancellations' => ['icon' => 'calendar-x', 'group' => 'bookings', 'filters' => ['range', 'source'], 'range' => 'past'],
        'no_shows' => ['icon' => 'circle-slash', 'group' => 'bookings', 'filters' => ['range', 'source'], 'range' => 'past'],
        'pickup' => ['icon' => 'trending-up', 'group' => 'bookings', 'filters' => ['range', 'pickup_days', 'room_type'], 'range' => 'future'],
        'taxes' => ['icon' => 'receipt', 'group' => 'finance', 'filters' => ['range'], 'range' => 'month'],
    ];

    public const GROUPS = ['performance', 'bookings', 'finance', 'operations'];

    public static function exists(string $key): bool
    {
        return isset(self::REPORTS[$key]);
    }

    /** URL slug (room-types) ↔ key (room_types). */
    public static function slug(string $key): string
    {
        return str_replace('_', '-', $key);
    }

    public static function key(string $slug): string
    {
        return str_replace('-', '_', $slug);
    }
}
