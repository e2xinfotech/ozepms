<?php

namespace App\Domain\Reports\Queries;

use App\Domain\Reports\Report;
use App\Domain\Reports\ReportFilter;
use Illuminate\Support\Facades\DB;

/**
 * Money actually posted and collected (the folio ledger by business date, payments by time),
 * taxes by component, and the daily manager report (day / month-to-date / year-to-date).
 * Voided charges count on their own date and their reversal on the void date, as in the folio.
 */
class LedgerReports
{
    public function __construct(
        private readonly StayReports $stays,
        private readonly BookingReports $bookings,
    ) {}

    public function revenue(ReportFilter $f): array
    {
        $rows = DB::table('folio_lines')->where('property_id', $f->property->id)
            ->whereBetween('business_date', [$f->fromDate(), $f->toDate()])
            ->selectRaw($f->periodSql('business_date').' AS period')
            ->selectRaw("SUM(CASE WHEN line_type = 'room' THEN amount ELSE 0 END) AS room")
            ->selectRaw("SUM(CASE WHEN line_type = 'service' THEN amount ELSE 0 END) AS service")
            ->selectRaw("SUM(CASE WHEN line_type = 'cancellation_fee' THEN amount ELSE 0 END) AS fees")
            ->selectRaw("SUM(CASE WHEN line_type IN ('discount', 'adjustment') THEN amount ELSE 0 END) AS adjustments")
            ->selectRaw('SUM(amount) AS net, SUM(tax_amount) AS tax')
            ->groupBy('period')->orderBy('period')->get()
            ->map(fn ($r) => [
                'period' => (string) $r->period, 'room' => Report::money($r->room), 'service' => Report::money($r->service), 'fees' => Report::money($r->fees),
                'adjustments' => Report::money($r->adjustments), 'net' => Report::money($r->net), 'tax' => Report::money($r->tax),
                'gross' => Report::money((float) $r->net + (float) $r->tax),
            ])->all();
        $sum = fn (string $k) => Report::money(array_sum(array_map('floatval', array_column($rows, $k))));
        $totals = ['period' => __('reports.total')] + collect(['room', 'service', 'fees', 'adjustments', 'net', 'tax', 'gross'])->mapWithKeys(fn ($k) => [$k => $sum($k)])->all();
        $prev = $this->posted($f->previous());
        $payments = $this->payments($f);

        $services = DB::table('folio_lines as l')->leftJoin('services as s', 's.id', '=', 'l.service_id')
            ->where('l.property_id', $f->property->id)->where('l.line_type', 'service')->whereBetween('l.business_date', [$f->fromDate(), $f->toDate()])
            ->groupBy('l.service_id', 's.name')->selectRaw('l.service_id, COALESCE(MAX(s.name), MAX(l.description)) AS name, SUM(l.quantity) AS qty, SUM(l.amount) AS amount')
            ->havingRaw('SUM(l.amount) <> 0')->orderByDesc('amount')->limit(20)->get()
            ->map(fn ($r) => ['name' => $r->name, 'quantity' => (float) $r->qty, 'net' => Report::money($r->amount)])->all();

        return [
            'summary' => [
                Report::kpi('posted_revenue', 'money', $totals['net'], $prev['net']),
                Report::kpi('room_charges', 'money', $totals['room'], $prev['room']),
                Report::kpi('extras', 'money', $totals['service'], $prev['service']),
                Report::kpi('taxes', 'money', $totals['tax'], $prev['tax']),
                Report::kpi('payments_collected', 'money', $payments['total'], $this->payments($f->previous())['total']),
            ],
            'chart' => $rows === [] ? null : [
                'labels' => array_column($rows, 'period'), 'label_type' => 'period',
                'bars' => array_map('floatval', array_column($rows, 'net')), 'line' => array_map('floatval', array_column($rows, 'room')),
                'bar_label' => __('reports.col.net'), 'line_label' => __('reports.col.room'), 'bar_type' => 'money', 'line_type' => 'money',
            ],
            'tables' => [
                Report::table('revenue', [
                    Report::col('period', 'period'), Report::col('room', 'money'), Report::col('service', 'money'), Report::col('fees', 'money'),
                    Report::col('adjustments', 'money'), Report::col('net', 'money'), Report::col('tax', 'money'), Report::col('gross', 'money'),
                ], $rows, $totals),
                Report::table('payments', [Report::col('method', 'text'), Report::col('count', 'int'), Report::col('received', 'money'), Report::col('refunded', 'money'), Report::col('net_received', 'money')],
                    $payments['rows'], ['method' => __('reports.total'), 'count' => array_sum(array_column($payments['rows'], 'count')), 'received' => $payments['received'], 'refunded' => $payments['refunded'], 'net_received' => $payments['total']]),
                Report::table('services', [Report::col('name', 'text', __('reports.col.service')), Report::col('quantity', 'decimal'), Report::col('net', 'money')], $services),
            ],
        ];
    }

    public function taxes(ReportFilter $f): array
    {
        $rows = DB::table('folio_line_taxes as t')->join('folio_lines as l', 'l.id', '=', 't.folio_line_id')
            ->where('l.property_id', $f->property->id)->whereBetween('l.business_date', [$f->fromDate(), $f->toDate()])
            ->groupBy('t.component', 't.tax_name', 't.rate')
            ->selectRaw('t.component, t.tax_name, t.rate, SUM(t.taxable_amount) AS taxable, SUM(t.tax_amount) AS tax, COUNT(DISTINCT l.folio_id) AS folios')
            ->orderBy('t.component')->orderBy('t.rate')->get()
            ->map(fn ($r) => ['component' => $r->component, 'name' => $r->tax_name, 'rate' => round((float) $r->rate, 2), 'taxable' => Report::money($r->taxable), 'tax' => Report::money($r->tax), 'folios' => (int) $r->folios])
            ->all();
        $invoices = DB::table('invoices')->where('property_id', $f->property->id)->whereBetween('invoice_date', [$f->fromDate(), $f->toDate()])
            ->groupBy('invoice_type')->selectRaw('invoice_type, COUNT(*) AS n')->pluck('n', 'invoice_type');
        $tax = Report::money(array_sum(array_map('floatval', array_column($rows, 'tax'))));
        $byPeriod = DB::table('folio_lines')->where('property_id', $f->property->id)->whereBetween('business_date', [$f->fromDate(), $f->toDate()])
            ->selectRaw($f->periodSql('business_date').' AS period, SUM(amount) AS taxable, SUM(tax_amount) AS tax')->groupBy('period')->orderBy('period')->get();

        return [
            'summary' => [
                Report::kpi('taxes', 'money', $tax),
                Report::kpi('taxable_value', 'money', Report::money($byPeriod->sum(fn ($r) => (float) $r->taxable))),
                Report::kpi('invoices', 'int', (int) ($invoices['tax_invoice'] ?? 0)),
                Report::kpi('credit_notes', 'int', (int) ($invoices['credit_note'] ?? 0)),
            ],
            'chart' => null,
            'tables' => [
                Report::table('taxes', [
                    Report::col('component', 'text'), Report::col('name', 'text', __('reports.col.tax_name')), Report::col('rate', 'percent'),
                    Report::col('taxable', 'money'), Report::col('tax', 'money'), Report::col('folios', 'int'),
                ], $rows, ['component' => __('reports.total'), 'tax' => $tax]),
                Report::table('tax_by_period', [Report::col('period', 'period'), Report::col('taxable', 'money'), Report::col('tax', 'money')],
                    $byPeriod->map(fn ($r) => ['period' => (string) $r->period, 'taxable' => Report::money($r->taxable), 'tax' => Report::money($r->tax)])->all()),
            ],
        ];
    }

    /** Daily manager report for one date: the day, month to date and year to date. */
    public function daily(ReportFilter $f): array
    {
        $day = $f->from;
        $ranges = [
            'day' => $this->range($f, $day, $day),
            'mtd' => $this->range($f, $day->startOfMonth(), $day),
            'ytd' => $this->range($f, $day->startOfYear(), $day),
        ];
        $m = [];
        foreach ($ranges as $k => $r) {
            $s = $this->stays->totals($r);
            $b = $this->bookings->counts($r);
            $p = $this->posted($r);
            $pay = $this->payments($r);
            $arr = (int) DB::table('stats_daily')->where('property_id', $f->property->id)->whereBetween('stay_date', [$r->fromDate(), $r->toDate()])->sum('arrivals');
            $dep = (int) DB::table('stats_daily')->where('property_id', $f->property->id)->whereBetween('stay_date', [$r->fromDate(), $r->toDate()])->sum('departures');
            $m[$k] = $s + ['posted' => $p['net'], 'tax' => $p['tax'], 'extras' => $p['service'], 'payments' => $pay['total'], 'arrivals' => $arr, 'departures' => $dep] + $b;
        }
        $metric = fn (string $key, string $type) => ['metric' => __('reports.kpi.'.$key), 'type' => $type, 'day' => $m['day'][$this->src($key)], 'mtd' => $m['mtd'][$this->src($key)], 'ytd' => $m['ytd'][$this->src($key)]];
        $rows = [
            $metric('rooms_available', 'int'), $metric('rooms_sold', 'int'), $metric('occupancy', 'percent'), $metric('adr', 'money'), $metric('revpar', 'money'),
            $metric('room_revenue', 'money'), $metric('extras', 'money'), $metric('posted_revenue', 'money'), $metric('taxes', 'money'), $metric('payments_collected', 'money'),
            $metric('arrivals', 'int'), $metric('departures', 'int'), $metric('bookings', 'int'), $metric('cancellations', 'int'), $metric('no_shows', 'int'),
        ];
        $lists = fn (string $kind) => Report::table($kind, [
            Report::col('ref', 'ref'), Report::col('guest', 'text'), Report::col('status', 'status'), Report::col('source', 'text'), Report::col('check_in', 'date'),
            Report::col('check_out', 'date'), Report::col('nights', 'int'), Report::col('rooms', 'int'), Report::col('guests', 'int'), Report::col('balance', 'money'),
        ], $this->bookings->dayList($f, $kind));
        $inHouse = $this->bookings->dayList($f, 'in_house');

        return [
            'summary' => [
                Report::kpi('occupancy', 'percent', $m['day']['occupancy']),
                Report::kpi('adr', 'money', $m['day']['adr']),
                Report::kpi('room_revenue', 'money', $m['day']['revenue']),
                Report::kpi('arrivals', 'int', $m['day']['arrivals']),
                Report::kpi('departures', 'int', $m['day']['departures']),
                Report::kpi('in_house', 'int', count($inHouse)),
            ],
            'chart' => null,
            'tables' => [
                Report::table('manager', [Report::col('metric', 'text'), Report::col('day', 'mixed'), Report::col('mtd', 'mixed'), Report::col('ytd', 'mixed')], $rows),
                $lists('arrivals'), $lists('departures'),
                Report::table('in_house', [
                    Report::col('ref', 'ref'), Report::col('guest', 'text'), Report::col('status', 'status'), Report::col('source', 'text'), Report::col('check_in', 'date'),
                    Report::col('check_out', 'date'), Report::col('nights', 'int'), Report::col('rooms', 'int'), Report::col('guests', 'int'), Report::col('balance', 'money'),
                ], $inHouse),
            ],
        ];
    }

    private function src(string $key): string
    {
        return ['rooms_available' => 'available', 'rooms_sold' => 'sold', 'room_revenue' => 'revenue', 'posted_revenue' => 'posted', 'taxes' => 'tax', 'payments_collected' => 'payments'][$key] ?? $key;
    }

    private function range(ReportFilter $f, $from, $to): ReportFilter
    {
        return new ReportFilter($f->property, $from, $to, 'day', $f->roomTypeId);
    }

    /** @return array{net: string, room: string, service: string, tax: string} posted in the range */
    private function posted(ReportFilter $f): array
    {
        $r = DB::table('folio_lines')->where('property_id', $f->property->id)->whereBetween('business_date', [$f->fromDate(), $f->toDate()])
            ->selectRaw("COALESCE(SUM(amount), 0) AS net, COALESCE(SUM(tax_amount), 0) AS tax, COALESCE(SUM(CASE WHEN line_type = 'room' THEN amount ELSE 0 END), 0) AS room, COALESCE(SUM(CASE WHEN line_type = 'service' THEN amount ELSE 0 END), 0) AS service")
            ->first();

        return ['net' => Report::money($r->net), 'room' => Report::money($r->room), 'service' => Report::money($r->service), 'tax' => Report::money($r->tax)];
    }

    /** Payments received and refunds paid in the range, by method. */
    private function payments(ReportFilter $f): array
    {
        [$a, $b] = $f->utcBounds();
        $rows = DB::table('payments')->where('property_id', $f->property->id)->where('received_at', '>=', $a)->where('received_at', '<', $b)
            ->groupBy('method')
            ->selectRaw("method, SUM(CASE WHEN kind = 'payment' AND status IN ('captured','refunded','partially_refunded') THEN 1 ELSE 0 END) AS n")
            ->selectRaw("COALESCE(SUM(CASE WHEN kind = 'payment' AND status IN ('captured','refunded','partially_refunded') THEN amount ELSE 0 END), 0) AS received")
            ->selectRaw("COALESCE(SUM(CASE WHEN kind = 'refund' AND status = 'captured' THEN amount ELSE 0 END), 0) AS refunded")
            ->orderBy('method')->get()
            ->filter(fn ($r) => (float) $r->received != 0 || (float) $r->refunded != 0)
            ->map(fn ($r) => ['method' => __('billing.methods.'.$r->method), 'count' => (int) $r->n, 'received' => Report::money($r->received), 'refunded' => Report::money($r->refunded), 'net_received' => Report::money((float) $r->received - (float) $r->refunded)])
            ->values()->all();
        $received = array_sum(array_map('floatval', array_column($rows, 'received')));
        $refunded = array_sum(array_map('floatval', array_column($rows, 'refunded')));

        return ['rows' => $rows, 'received' => Report::money($received), 'refunded' => Report::money($refunded), 'total' => Report::money($received - $refunded)];
    }
}
