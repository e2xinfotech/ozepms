<?php

namespace App\Domain\Reports;

use App\Models\Property;
use Carbon\CarbonImmutable;

/**
 * Filters of one report run (already validated by ReportRequest). Dates are inclusive and in the
 * property's calendar. Ids are internal ids resolved from the public ids of the request.
 */
final class ReportFilter
{
    public const GROUPS = ['day', 'week', 'month'];

    public function __construct(
        public readonly Property $property,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $group = 'day',
        public readonly ?int $roomTypeId = null,
        public readonly ?int $ratePlanId = null,
        public readonly ?int $sourceId = null,
        public readonly ?string $status = null,
        public readonly string $basis = 'booked',
        public readonly int $pickupDays = 7,
        public readonly int $page = 1,
    ) {}

    public function fromDate(): string
    {
        return $this->from->toDateString();
    }

    public function toDate(): string
    {
        return $this->to->toDateString();
    }

    /** Exclusive end, for half-open ranges. */
    public function toExclusive(): string
    {
        return $this->to->addDay()->toDateString();
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    /** The same number of days just before this range (for "vs previous period"). */
    public function previous(): self
    {
        $days = $this->days();

        return new self($this->property, $this->from->subDays($days), $this->from->subDay(), $this->group,
            $this->roomTypeId, $this->ratePlanId, $this->sourceId, $this->status, $this->basis, $this->pickupDays, 1);
    }

    /** SQL expression grouping a DATE column into the selected period (first day of the period). */
    public function periodSql(string $column): string
    {
        return match ($this->group) {
            'week' => "DATE_SUB({$column}, INTERVAL WEEKDAY({$column}) DAY)",
            'month' => "DATE_FORMAT({$column}, '%Y-%m-01')",
            default => $column,
        };
    }

    /** UTC bounds of the range for DATETIME columns stored in UTC (created_at, cancelled_at …). */
    public function utcBounds(): array
    {
        $tz = $this->property->timezone ?: config('app.timezone');

        return [
            CarbonImmutable::parse($this->fromDate(), $tz)->startOfDay()->utc()->format('Y-m-d H:i:s'),
            CarbonImmutable::parse($this->toExclusive(), $tz)->startOfDay()->utc()->format('Y-m-d H:i:s'),
        ];
    }

    /** SQL expression turning a UTC DATETIME column into the property's local DATE. */
    public function localDateSql(string $column): string
    {
        $offset = CarbonImmutable::now($this->property->timezone ?: config('app.timezone'))->format('P');

        return "DATE(CONVERT_TZ({$column}, '+00:00', '{$offset}'))";
    }
}
