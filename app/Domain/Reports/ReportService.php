<?php

namespace App\Domain\Reports;

use App\Domain\Reports\Queries\BookingReports;
use App\Domain\Reports\Queries\LedgerReports;
use App\Domain\Reports\Queries\StayReports;

/** Runs a report of the catalog and turns its main table into CSV rows. */
class ReportService
{
    public function __construct(
        private readonly StayReports $stays,
        private readonly BookingReports $bookings,
        private readonly LedgerReports $ledger,
    ) {}

    public function run(string $key, ReportFilter $f, bool $export = false): array
    {
        return match ($key) {
            'overview' => $this->stays->overview($f),
            'occupancy' => $this->stays->occupancy($f),
            'room_types' => $this->stays->roomTypes($f),
            'rate_plans' => $this->stays->ratePlans($f),
            'sources' => $this->stays->sources($f),
            'pickup' => $this->stays->pickup($f),
            'reservations' => $this->bookings->list($f, $export),
            'cancellations' => $this->bookings->cancellations($f, $export),
            'no_shows' => $this->bookings->noShows($f, $export),
            'revenue' => $this->ledger->revenue($f),
            'taxes' => $this->ledger->taxes($f),
            'daily' => $this->ledger->daily($f),
            default => throw new \InvalidArgumentException("Unknown report {$key}"),
        };
    }

    /**
     * CSV rows (header first) of every table of the report, separated by an empty line.
     *
     * @return \Generator<int, list<string|int|float>>
     */
    public function csv(string $key, ReportFilter $f): \Generator
    {
        $report = $this->run($key, $f, true);
        foreach ($report['tables'] as $i => $table) {
            if ($i > 0) {
                yield [];
                yield [$table['title']];
            }
            yield array_column($table['columns'], 'label');
            foreach ($table['rows'] as $row) {
                yield $this->csvRow($table['columns'], $row, $f);
            }
            if (! empty($table['totals'])) {
                yield $this->csvRow($table['columns'], $table['totals'], $f);
            }
        }
    }

    private function csvRow(array $columns, array $row, ReportFilter $f): array
    {
        return array_map(function (array $c) use ($row, $f) {
            $v = $row[$c['key']] ?? '';
            if ($v === '' || $v === null) {
                return '';
            }

            return match ($c['type']) {
                'status' => __('reservations.status.'.$v),
                'period' => $this->period((string) $v, $f->group),
                'mixed' => ($row['type'] ?? '') === 'percent' ? $v.'%' : $v,
                default => $v,
            };
        }, $columns);
    }

    private function period(string $v, string $group): string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return $v;
        }

        return match ($group) {
            'month' => substr($v, 0, 7),
            'week' => __('reports.week_of', ['date' => $v]),
            default => $v,
        };
    }
}
