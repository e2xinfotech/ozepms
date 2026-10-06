<?php

namespace App\Domain\Reports\Queries;

use App\Domain\Reports\Report;
use App\Domain\Reports\ReportFilter;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reports by stay night, read from the rollups (stats_daily, stats_daily_mix): overview,
 * occupancy, room types, rate plans, booking sources and pickup.
 */
class StayReports
{
    public function __construct(private readonly BookingReports $bookings) {}

    public function overview(ReportFilter $f): array
    {
        $now = $this->totals($f);
        $before = $this->totals($f->previous());
        $b = $this->bookings->counts($f);
        $bp = $this->bookings->counts($f->previous());
        $periods = $this->periods($f);

        return [
            'summary' => [
                Report::kpi('occupancy', 'percent', $now['occupancy'], $before['occupancy']),
                Report::kpi('adr', 'money', $now['adr'], $before['adr']),
                Report::kpi('revpar', 'money', $now['revpar'], $before['revpar']),
                Report::kpi('room_revenue', 'money', $now['revenue'], $before['revenue']),
                Report::kpi('rooms_sold', 'int', $now['sold'], $before['sold']),
                Report::kpi('bookings', 'int', $b['bookings'], $bp['bookings']),
                Report::kpi('cancellations', 'int', $b['cancellations'], $bp['cancellations']),
                Report::kpi('no_shows', 'int', $b['no_shows'], $bp['no_shows']),
                Report::kpi('avg_los', 'decimal', $b['avg_los'], $bp['avg_los']),
                Report::kpi('avg_lead', 'decimal', $b['avg_lead'], $bp['avg_lead']),
            ],
            'chart' => $this->chart($periods, 'occupancy', 'revenue'),
            'tables' => [Report::table('by_period', [
                Report::col('period', 'period'), Report::col('available', 'int'), Report::col('sold', 'int'), Report::col('occupancy', 'percent'),
                Report::col('adr', 'money'), Report::col('revpar', 'money'), Report::col('revenue', 'money'),
            ], $periods, ['period' => __('reports.total')] + $now)],
        ];
    }

    public function occupancy(ReportFilter $f): array
    {
        $rows = $this->base($f)
            ->selectRaw($f->periodSql('stay_date').' AS period, SUM(units_total) AS units, SUM(units_ooo) AS ooo, SUM(rooms_sold) AS sold, SUM(room_revenue) AS revenue, SUM(arrivals) AS arrivals, SUM(departures) AS departures, SUM(no_shows) AS no_shows')
            ->groupBy('period')->orderBy('period')->get()
            ->map(fn ($r) => ['period' => (string) $r->period, 'units' => (int) $r->units, 'ooo' => (int) $r->ooo]
                + Report::performance((int) $r->units - (int) $r->ooo, (int) $r->sold, $r->revenue)
                + ['arrivals' => (int) $r->arrivals, 'departures' => (int) $r->departures, 'no_shows' => (int) $r->no_shows])
            ->all();
        $t = $this->totals($f);
        $sum = fn (string $k) => array_sum(array_column($rows, $k));

        return [
            'summary' => [
                Report::kpi('occupancy', 'percent', $t['occupancy'], $this->totals($f->previous())['occupancy']),
                Report::kpi('rooms_available', 'int', $t['available']),
                Report::kpi('rooms_sold', 'int', $t['sold']),
                Report::kpi('rooms_ooo', 'int', $sum('ooo')),
                Report::kpi('adr', 'money', $t['adr']),
                Report::kpi('revpar', 'money', $t['revpar']),
            ],
            'chart' => $this->chart($rows, 'occupancy', 'adr'),
            'tables' => [Report::table('by_period', [
                Report::col('period', 'period'), Report::col('units', 'int'), Report::col('ooo', 'int'), Report::col('available', 'int'),
                Report::col('sold', 'int'), Report::col('occupancy', 'percent'), Report::col('adr', 'money'), Report::col('revpar', 'money'),
                Report::col('arrivals', 'int'), Report::col('departures', 'int'), Report::col('no_shows', 'int'),
            ], $rows, ['period' => __('reports.total'), 'units' => $sum('units'), 'ooo' => $sum('ooo'), 'arrivals' => $sum('arrivals'), 'departures' => $sum('departures'), 'no_shows' => $sum('no_shows')] + $t)],
        ];
    }

    public function roomTypes(ReportFilter $f): array
    {
        $data = $this->base($f)->join('room_types as rt', 'rt.id', '=', 'stats_daily.room_type_id')
            ->selectRaw('rt.id, rt.name, rt.sort_order, SUM(units_total - units_ooo) AS available, SUM(rooms_sold) AS sold, SUM(room_revenue) AS revenue')
            ->groupBy('rt.id', 'rt.name', 'rt.sort_order')->orderBy('rt.sort_order')->orderBy('rt.name')->get();
        $totalRevenue = (string) $data->sum(fn ($r) => (float) $r->revenue);
        $rows = $data->map(fn ($r) => ['name' => $r->name] + Report::performance((int) $r->available, (int) $r->sold, $r->revenue)
            + ['share' => Report::pct($r->revenue, $totalRevenue)])->values()->all();
        $t = $this->totals($f);
        $best = collect($rows)->sortByDesc('revenue')->first();

        return [
            'summary' => [
                Report::kpi('room_revenue', 'money', $t['revenue']),
                Report::kpi('occupancy', 'percent', $t['occupancy']),
                Report::kpi('adr', 'money', $t['adr']),
                Report::kpi('top_room_type', 'text', $best['name'] ?? '—'),
            ],
            'chart' => $this->namedChart($rows, 'name', 'revenue', 'occupancy'),
            'tables' => [Report::table('by_room_type', [
                Report::col('name', 'text', __('reports.col.room_type')), Report::col('available', 'int'), Report::col('sold', 'int'), Report::col('occupancy', 'percent'),
                Report::col('adr', 'money'), Report::col('revpar', 'money'), Report::col('revenue', 'money'), Report::col('share', 'percent'),
            ], $rows, ['name' => __('reports.total'), 'share' => $rows ? 100.0 : 0.0] + $t)],
        ];
    }

    public function ratePlans(ReportFilter $f): array
    {
        $data = $this->mix($f)->join('rate_plans as rp', 'rp.id', '=', 'm.rate_plan_id')
            ->selectRaw('rp.id, rp.name, rp.code, SUM(m.rooms_sold) AS sold, SUM(m.room_revenue) AS revenue, SUM(m.discount) AS discount, SUM(m.guests) AS guests')
            ->groupBy('rp.id', 'rp.name', 'rp.code')->orderByDesc('revenue')->get();

        return $this->mixReport($data, 'rate_plan', 'by_rate_plan', fn ($r) => $r->name);
    }

    public function sources(ReportFilter $f): array
    {
        $data = $this->mix($f)->join('booking_sources as bs', 'bs.id', '=', 'm.source_id')
            ->selectRaw('bs.id, bs.name, bs.code, SUM(m.rooms_sold) AS sold, SUM(m.room_revenue) AS revenue, SUM(m.discount) AS discount, SUM(m.guests) AS guests')
            ->groupBy('bs.id', 'bs.name', 'bs.code')->orderByDesc('revenue')->get();
        $made = $this->bookings->bySource($f);
        $report = $this->mixReport($data, 'source', 'by_source', fn ($r) => BookingReports::sourceName($r->code, $r->name), function (array $row, $r) use ($made) {
            return $row + ['bookings' => (int) ($made[$r->id]['bookings'] ?? 0), 'cancellations' => (int) ($made[$r->id]['cancellations'] ?? 0)];
        });
        $report['tables'][0]['columns'][] = Report::col('bookings', 'int');
        $report['tables'][0]['columns'][] = Report::col('cancellations', 'int');
        $report['tables'][0]['totals'] += ['bookings' => array_sum(array_column($report['tables'][0]['rows'], 'bookings')), 'cancellations' => array_sum(array_column($report['tables'][0]['rows'], 'cancellations'))];

        return $report;
    }

    /**
     * Pickup: rooms and revenue on the books for each stay date now compared with N days ago
     * (bookings made since then minus cancellations since then). Changes to the dates of an
     * existing booking count at their current dates.
     */
    public function pickup(ReportFilter $f): array
    {
        $then = now()->subDays($f->pickupDays)->format('Y-m-d H:i:s');
        $nowRows = $this->base($f)
            ->selectRaw($f->periodSql('stay_date').' AS period, SUM(units_total - units_ooo) AS available, SUM(rooms_sold) AS sold, SUM(room_revenue) AS revenue')
            ->groupBy('period')->get()->keyBy('period');
        $thenRows = DB::table('reservation_room_nights as n')
            ->join('reservation_rooms as rr', 'rr.id', '=', 'n.reservation_room_id')
            ->join('reservations as r', 'r.id', '=', 'rr.reservation_id')
            ->where('n.property_id', $f->property->id)
            ->whereBetween('n.stay_date', [$f->fromDate(), $f->toDate()])
            ->when($f->roomTypeId, fn ($q, $id) => $q->where('n.room_type_id', $id))
            ->where('r.created_at', '<=', $then)
            ->where(fn ($q) => $q->where('n.is_active', 1)->orWhere(fn ($c) => $c->whereIn('rr.status', ['cancelled', 'no_show'])->where('r.cancelled_at', '>', $then)))
            ->selectRaw($f->periodSql('n.stay_date').' AS period, COUNT(*) AS sold, SUM(n.net_price) AS revenue')
            ->groupBy('period')->get()->keyBy('period');

        $rows = [];
        foreach ($nowRows as $period => $n) {
            $t = $thenRows[$period] ?? null;
            $rows[] = [
                'period' => (string) $period,
                'available' => (int) $n->available,
                'sold' => (int) $n->sold,
                'occupancy' => Report::pct($n->sold, $n->available),
                'sold_then' => (int) ($t->sold ?? 0),
                'pickup' => (int) $n->sold - (int) ($t->sold ?? 0),
                'revenue' => Report::money($n->revenue),
                'revenue_then' => Report::money($t->revenue ?? 0),
                'revenue_pickup' => Report::money((float) $n->revenue - (float) ($t->revenue ?? 0)),
            ];
        }
        usort($rows, fn ($a, $b) => strcmp($a['period'], $b['period']));
        $sum = fn (string $k) => array_sum(array_column($rows, $k));
        $sumMoney = fn (string $k) => Report::money(array_sum(array_map('floatval', array_column($rows, $k))));

        return [
            'summary' => [
                Report::kpi('on_the_books', 'int', $sum('sold')),
                Report::kpi('pickup_rooms', 'int', $sum('pickup')),
                Report::kpi('pickup_revenue', 'money', $sumMoney('revenue_pickup')),
                Report::kpi('forecast_occupancy', 'percent', Report::pct($sum('sold'), $sum('available'))),
            ],
            'chart' => $this->chart($rows, 'pickup', 'occupancy', 'int', 'percent'),
            'tables' => [Report::table('pickup', [
                Report::col('period', 'period'), Report::col('available', 'int'), Report::col('sold', 'int', __('reports.col.on_the_books')),
                Report::col('sold_then', 'int', __('reports.col.on_the_books_then', ['days' => $f->pickupDays])), Report::col('pickup', 'int'),
                Report::col('occupancy', 'percent'), Report::col('revenue', 'money'),
                Report::col('revenue_then', 'money', __('reports.col.revenue_then', ['days' => $f->pickupDays])), Report::col('revenue_pickup', 'money'),
            ], $rows, ['period' => __('reports.total'), 'available' => $sum('available'), 'sold' => $sum('sold'), 'sold_then' => $sum('sold_then'), 'pickup' => $sum('pickup'),
                'occupancy' => Report::pct($sum('sold'), $sum('available')), 'revenue' => $sumMoney('revenue'), 'revenue_then' => $sumMoney('revenue_then'), 'revenue_pickup' => $sumMoney('revenue_pickup')])],
        ];
    }

    /** Totals of the range: available, sold, occupancy, revenue, ADR, RevPAR. */
    public function totals(ReportFilter $f): array
    {
        $t = $this->base($f)->selectRaw('COALESCE(SUM(units_total - units_ooo), 0) AS available, COALESCE(SUM(rooms_sold), 0) AS sold, COALESCE(SUM(room_revenue), 0) AS revenue')->first();

        return Report::performance((int) $t->available, (int) $t->sold, $t->revenue);
    }

    /** Per period: available, sold, occupancy, revenue, ADR, RevPAR. */
    public function periods(ReportFilter $f): array
    {
        return $this->base($f)
            ->selectRaw($f->periodSql('stay_date').' AS period, SUM(units_total - units_ooo) AS available, SUM(rooms_sold) AS sold, SUM(room_revenue) AS revenue')
            ->groupBy('period')->orderBy('period')->get()
            ->map(fn ($r) => ['period' => (string) $r->period] + Report::performance((int) $r->available, (int) $r->sold, $r->revenue))
            ->all();
    }

    private function base(ReportFilter $f): Builder
    {
        return DB::table('stats_daily')->where('stats_daily.property_id', $f->property->id)
            ->whereBetween('stats_daily.stay_date', [$f->fromDate(), $f->toDate()])
            ->when($f->roomTypeId, fn ($q, $id) => $q->where('stats_daily.room_type_id', $id));
    }

    private function mix(ReportFilter $f): Builder
    {
        return DB::table('stats_daily_mix as m')->where('m.property_id', $f->property->id)
            ->whereBetween('m.stay_date', [$f->fromDate(), $f->toDate()])
            ->when($f->roomTypeId, fn ($q, $id) => $q->where('m.room_type_id', $id));
    }

    private function mixReport($data, string $nameCol, string $table, callable $name, ?callable $extra = null): array
    {
        $nights = (int) $data->sum('sold');
        $revenue = (string) $data->sum(fn ($r) => (float) $r->revenue);
        $rows = $data->map(function ($r) use ($nights, $revenue, $name, $extra) {
            $row = [
                'name' => $name($r),
                'sold' => (int) $r->sold,
                'nights_share' => Report::pct($r->sold, $nights),
                'revenue' => Report::money($r->revenue),
                'share' => Report::pct($r->revenue, $revenue),
                'adr' => Report::ratio($r->revenue, $r->sold),
                'discount' => Report::money($r->discount),
                'avg_guests' => (int) $r->sold > 0 ? round((int) $r->guests / (int) $r->sold, 1) : 0,
            ];

            return $extra ? $extra($row, $r) : $row;
        })->values()->all();
        $top = $rows[0]['name'] ?? '—';

        return [
            'summary' => [
                Report::kpi('room_revenue', 'money', Report::money($revenue)),
                Report::kpi('rooms_sold', 'int', $nights),
                Report::kpi('adr', 'money', Report::ratio($revenue, $nights)),
                Report::kpi('top_'.$nameCol, 'text', $top),
            ],
            'chart' => $this->namedChart($rows, 'name', 'revenue', 'sold', 'money', 'int'),
            'tables' => [Report::table($table, [
                Report::col('name', 'text', __('reports.col.'.$nameCol)), Report::col('sold', 'int'), Report::col('nights_share', 'percent'),
                Report::col('revenue', 'money'), Report::col('share', 'percent'), Report::col('adr', 'money'), Report::col('discount', 'money'), Report::col('avg_guests', 'decimal'),
            ], $rows, [
                'name' => __('reports.total'), 'sold' => $nights, 'nights_share' => $rows ? 100.0 : 0.0, 'revenue' => Report::money($revenue), 'share' => $rows ? 100.0 : 0.0,
                'adr' => Report::ratio($revenue, $nights), 'discount' => Report::money($data->sum(fn ($r) => (float) $r->discount)),
                'avg_guests' => $nights > 0 ? round($data->sum('guests') / $nights, 1) : 0,
            ])],
        ];
    }

    private function chart(array $rows, string $bar, string $line, string $barType = 'percent', string $lineType = 'money'): ?array
    {
        if ($rows === []) {
            return null;
        }

        return [
            'labels' => array_column($rows, 'period'), 'label_type' => 'period',
            'bars' => array_map('floatval', array_column($rows, $bar)), 'line' => array_map('floatval', array_column($rows, $line)),
            'bar_label' => __('reports.col.'.$bar), 'line_label' => __('reports.col.'.$line),
            'bar_type' => $barType, 'line_type' => $lineType,
        ];
    }

    private function namedChart(array $rows, string $label, string $bar, string $line, string $barType = 'money', string $lineType = 'percent'): ?array
    {
        if ($rows === []) {
            return null;
        }

        return [
            'labels' => array_column($rows, $label), 'label_type' => 'text',
            'bars' => array_map('floatval', array_column($rows, $bar)), 'line' => array_map('floatval', array_column($rows, $line)),
            'bar_label' => __('reports.col.'.$bar), 'line_label' => __('reports.col.'.$line),
            'bar_type' => $barType, 'line_type' => $lineType,
        ];
    }
}
