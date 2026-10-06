<?php

namespace App\Domain\Reports\Queries;

use App\Domain\Reports\Report;
use App\Domain\Reports\ReportFilter;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reports by booking: reservations made / arriving / staying, cancellations, no-shows, and the
 * booking counts used by the overview and sources reports. Reads `reservations` through its
 * property indexes (created, status, source), always bounded by property and dates, 50 rows a page.
 */
class BookingReports
{
    public const PER_PAGE = 50;

    /** Export cap: a CSV never holds more rows than this. */
    public const EXPORT_LIMIT = 20000;

    public static function sourceName(?string $code, ?string $name): string
    {
        $key = 'reservations.sources.'.$code;

        return $code !== null && __($key) !== $key ? __($key) : (string) $name;
    }

    /** Bookings made, cancellations, no-shows, average stay and lead time of the range. */
    public function counts(ReportFilter $f): array
    {
        [$a, $b] = $f->utcBounds();
        $made = $this->reservations($f->property->id)->where('r.created_at', '>=', $a)->where('r.created_at', '<', $b)->whereNot('r.status', 'inquiry')
            ->selectRaw('COUNT(*) AS n, AVG(r.nights) AS los, AVG(DATEDIFF(r.check_in, '.$f->localDateSql('r.created_at').')) AS lead_time')->first();
        $cancelled = $this->reservations($f->property->id)->where('r.status', 'cancelled')->where('r.cancelled_at', '>=', $a)->where('r.cancelled_at', '<', $b)->count();
        $noShows = $this->reservations($f->property->id)->where('r.status', 'no_show')->whereBetween('r.check_in', [$f->fromDate(), $f->toDate()])->count();

        return [
            'bookings' => (int) $made->n,
            'cancellations' => $cancelled,
            'no_shows' => $noShows,
            'avg_los' => round((float) $made->los, 1),
            'avg_lead' => round(max(0, (float) $made->lead_time), 1),
        ];
    }

    /** @return array<int, array{bookings: int, cancellations: int}> by source id */
    public function bySource(ReportFilter $f): array
    {
        [$a, $b] = $f->utcBounds();
        $out = [];
        foreach ($this->reservations($f->property->id)->where('r.created_at', '>=', $a)->where('r.created_at', '<', $b)->whereNot('r.status', 'inquiry')
            ->groupBy('r.source_id')->selectRaw('r.source_id, COUNT(*) AS n')->get() as $r) {
            $out[(int) $r->source_id]['bookings'] = (int) $r->n;
        }
        foreach ($this->reservations($f->property->id)->where('r.status', 'cancelled')->where('r.cancelled_at', '>=', $a)->where('r.cancelled_at', '<', $b)
            ->groupBy('r.source_id')->selectRaw('r.source_id, COUNT(*) AS n')->get() as $r) {
            $out[(int) $r->source_id]['cancellations'] = (int) $r->n;
        }

        return $out;
    }

    public function list(ReportFilter $f, bool $export = false): array
    {
        $q = $this->filtered($f);
        $s = (clone $q)->selectRaw('COUNT(*) AS n, COALESCE(SUM(r.nights * r.room_count), 0) AS nights, COALESCE(SUM(CASE WHEN r.status NOT IN (\'cancelled\', \'no_show\') THEN r.grand_total ELSE 0 END), 0) AS value, AVG(r.nights) AS los, AVG(DATEDIFF(r.check_in, '.$f->localDateSql('r.created_at').')) AS lead_time')->first();
        $total = (int) $s->n;
        $rows = $this->rows($q, $f, $export);

        return [
            'summary' => [
                Report::kpi('reservations', 'int', $total),
                Report::kpi('room_nights', 'int', (int) $s->nights),
                Report::kpi('booking_value', 'money', Report::money($s->value)),
                Report::kpi('avg_value', 'money', Report::ratio($s->value, $total)),
                Report::kpi('avg_los', 'decimal', round((float) $s->los, 1)),
                Report::kpi('avg_lead', 'decimal', round(max(0, (float) $s->lead_time), 1)),
            ],
            'chart' => null,
            'tables' => [Report::table('reservations', [
                Report::col('ref', 'ref'), Report::col('guest', 'text'), Report::col('status', 'status'), Report::col('source', 'text'),
                Report::col('booked_on', 'date'), Report::col('check_in', 'date'), Report::col('check_out', 'date'), Report::col('nights', 'int'),
                Report::col('rooms', 'int'), Report::col('guests', 'int'), Report::col('grand_total', 'money'), Report::col('paid', 'money'), Report::col('lead_days', 'int'),
            ], $rows, null, $export ? null : $this->pagination($f, $total))],
        ];
    }

    public function cancellations(ReportFilter $f, bool $export = false): array
    {
        [$a, $b] = $f->utcBounds();
        // Value of the cancelled stay: the booked nights incl. tax (the booking's own totals are cleared on cancellation).
        $lost = '(SELECT COALESCE(SUM(n.net_price + n.tax_amount), 0) FROM reservation_room_nights n JOIN reservation_rooms rr ON rr.id = n.reservation_room_id WHERE rr.reservation_id = r.id)';
        $q = $this->reservations($f->property->id)->where('r.status', 'cancelled')->where('r.cancelled_at', '>=', $a)->where('r.cancelled_at', '<', $b)
            ->when($f->sourceId, fn ($x, $id) => $x->where('r.source_id', $id));
        $s = (clone $q)->selectRaw('COUNT(*) AS n, COALESCE(SUM(r.nights * r.room_count), 0) AS nights, COALESCE(SUM('.$lost.'), 0) AS value, COALESCE(SUM(r.cancellation_fee), 0) AS fees, AVG(DATEDIFF(r.check_in, '.$f->localDateSql('r.cancelled_at').')) AS notice')->first();
        $made = $this->counts($f)['bookings'];
        $rows = (clone $q)->leftJoin('booking_sources as bs', 'bs.id', '=', 'r.source_id')
            ->orderByDesc('r.cancelled_at')->orderByDesc('r.id')
            ->when(! $export, fn ($x) => $x->forPage($f->page, self::PER_PAGE), fn ($x) => $x->limit(self::EXPORT_LIMIT))
            ->get(['r.public_id', 'r.booking_ref', 'r.guest_name', 'bs.code as source_code', 'bs.name as source_name', 'r.created_at', 'r.cancelled_at', 'r.check_in', 'r.nights', 'r.grand_total', 'r.cancellation_fee', 'r.cancel_reason', DB::raw($lost.' AS lost')])
            ->map(fn ($r) => [
                'id' => $r->public_id, 'ref' => $r->booking_ref, 'guest' => $r->guest_name, 'source' => self::sourceName($r->source_code, $r->source_name),
                'booked_on' => $this->local($r->created_at, $f), 'cancelled_on' => $this->local($r->cancelled_at, $f), 'check_in' => (string) $r->check_in,
                'notice_days' => max(0, (int) round((strtotime((string) $r->check_in) - strtotime($this->local($r->cancelled_at, $f))) / 86400)),
                'nights' => (int) $r->nights, 'stay_value' => Report::money($r->lost), 'fee' => Report::money($r->cancellation_fee ?? 0), 'reason' => (string) ($r->cancel_reason ?? ''),
            ])->all();
        $bySource = (clone $q)->leftJoin('booking_sources as bs', 'bs.id', '=', 'r.source_id')->groupBy('bs.code', 'bs.name')
            ->selectRaw('bs.code, bs.name, COUNT(*) AS n, SUM('.$lost.') AS value, SUM(COALESCE(r.cancellation_fee, 0)) AS fees')->orderByDesc('n')->get()
            ->map(fn ($r) => ['name' => self::sourceName($r->code, $r->name), 'cancellations' => (int) $r->n, 'stay_value' => Report::money($r->value), 'fee' => Report::money($r->fees)])->all();

        return [
            'summary' => [
                Report::kpi('cancellations', 'int', (int) $s->n),
                Report::kpi('cancellation_rate', 'percent', Report::pct($s->n, $made)),
                Report::kpi('room_nights_lost', 'int', (int) $s->nights),
                Report::kpi('revenue_lost', 'money', Report::money($s->value)),
                Report::kpi('fees_charged', 'money', Report::money($s->fees)),
                Report::kpi('avg_notice', 'decimal', round(max(0, (float) $s->notice), 1)),
            ],
            'chart' => null,
            'tables' => [
                Report::table('cancellations', [
                    Report::col('ref', 'ref'), Report::col('guest', 'text'), Report::col('source', 'text'), Report::col('booked_on', 'date'),
                    Report::col('cancelled_on', 'date'), Report::col('check_in', 'date'), Report::col('notice_days', 'int'), Report::col('nights', 'int'),
                    Report::col('stay_value', 'money'), Report::col('fee', 'money'), Report::col('reason', 'text'),
                ], $rows, null, $export ? null : $this->pagination($f, (int) $s->n)),
                Report::table('by_source', [Report::col('name', 'text', __('reports.col.source')), Report::col('cancellations', 'int'), Report::col('stay_value', 'money'), Report::col('fee', 'money')], $bySource),
            ],
        ];
    }

    public function noShows(ReportFilter $f, bool $export = false): array
    {
        $q = $this->reservations($f->property->id)->where('r.status', 'no_show')->whereBetween('r.check_in', [$f->fromDate(), $f->toDate()])
            ->when($f->sourceId, fn ($x, $id) => $x->where('r.source_id', $id));
        $s = (clone $q)->selectRaw('COUNT(*) AS n, COALESCE(SUM(r.nights * r.room_count), 0) AS nights, COALESCE(SUM(r.grand_total), 0) AS value, COALESCE(SUM(r.cancellation_fee), 0) AS fees')->first();
        $expected = (int) DB::table('stats_daily')->where('property_id', $f->property->id)->whereBetween('stay_date', [$f->fromDate(), $f->toDate()])
            ->selectRaw('COALESCE(SUM(arrivals + no_shows), 0) AS n')->value('n');
        $rows = (clone $q)->leftJoin('booking_sources as bs', 'bs.id', '=', 'r.source_id')->orderByDesc('r.check_in')->orderByDesc('r.id')
            ->when(! $export, fn ($x) => $x->forPage($f->page, self::PER_PAGE), fn ($x) => $x->limit(self::EXPORT_LIMIT))
            ->get(['r.public_id', 'r.booking_ref', 'r.guest_name', 'r.guest_phone', 'bs.code as source_code', 'bs.name as source_name', 'r.created_at', 'r.check_in', 'r.nights', 'r.room_count', 'r.grand_total', 'r.cancellation_fee'])
            ->map(fn ($r) => [
                'id' => $r->public_id, 'ref' => $r->booking_ref, 'guest' => $r->guest_name, 'phone' => (string) ($r->guest_phone ?? ''),
                'source' => self::sourceName($r->source_code, $r->source_name), 'booked_on' => $this->local($r->created_at, $f), 'check_in' => (string) $r->check_in,
                'nights' => (int) $r->nights, 'rooms' => (int) $r->room_count, 'grand_total' => Report::money($r->grand_total), 'fee' => Report::money($r->cancellation_fee ?? 0),
            ])->all();

        return [
            'summary' => [
                Report::kpi('no_shows', 'int', (int) $s->n),
                Report::kpi('no_show_rate', 'percent', Report::pct($s->n, $expected)),
                Report::kpi('room_nights_lost', 'int', (int) $s->nights),
                Report::kpi('revenue_lost', 'money', Report::money($s->value)),
                Report::kpi('fees_charged', 'money', Report::money($s->fees)),
            ],
            'chart' => null,
            'tables' => [Report::table('no_shows', [
                Report::col('ref', 'ref'), Report::col('guest', 'text'), Report::col('phone', 'text'), Report::col('source', 'text'), Report::col('booked_on', 'date'),
                Report::col('check_in', 'date'), Report::col('nights', 'int'), Report::col('rooms', 'int'), Report::col('grand_total', 'money'), Report::col('fee', 'money'),
            ], $rows, null, $export ? null : $this->pagination($f, (int) $s->n))],
        ];
    }

    /** Arrivals, departures or in-house guests of one date (daily report lists). */
    public function dayList(ReportFilter $f, string $kind): array
    {
        $d = $f->fromDate();
        $q = $this->reservations($f->property->id)->leftJoin('booking_sources as bs', 'bs.id', '=', 'r.source_id');
        match ($kind) {
            'arrivals' => $q->where('r.check_in', $d)->whereIn('r.status', ['pending', 'confirmed', 'checked_in', 'checked_out']),
            'departures' => $q->where('r.check_out', $d)->whereIn('r.status', ['confirmed', 'checked_in', 'checked_out']),
            default => $q->where('r.check_in', '<=', $d)->where('r.check_out', '>', $d)->whereIn('r.status', ['checked_in', 'confirmed']),
        };

        return $q->orderBy('r.guest_name')->limit(500)
            ->get(['r.public_id', 'r.booking_ref', 'r.guest_name', 'r.status', 'bs.code as source_code', 'bs.name as source_name', 'r.check_in', 'r.check_out', 'r.nights', 'r.room_count', 'r.adults', 'r.children', 'r.grand_total', 'r.paid_total'])
            ->map(fn ($r) => [
                'id' => $r->public_id, 'ref' => $r->booking_ref, 'guest' => $r->guest_name, 'status' => $r->status,
                'source' => self::sourceName($r->source_code, $r->source_name), 'check_in' => (string) $r->check_in, 'check_out' => (string) $r->check_out,
                'nights' => (int) $r->nights, 'rooms' => (int) $r->room_count, 'guests' => (int) $r->adults + (int) $r->children,
                'balance' => Report::money((float) $r->grand_total - (float) $r->paid_total),
            ])->all();
    }

    private function filtered(ReportFilter $f): Builder
    {
        [$a, $b] = $f->utcBounds();
        $q = $this->reservations($f->property->id);
        match ($f->basis) {
            'arrival' => $q->whereBetween('r.check_in', [$f->fromDate(), $f->toDate()]),
            'stay' => $q->where('r.check_in', '<=', $f->toDate())->where('r.check_out', '>', $f->fromDate()),
            default => $q->where('r.created_at', '>=', $a)->where('r.created_at', '<', $b),
        };

        return $q->when($f->status, fn ($x, $s) => $x->where('r.status', $s), fn ($x) => $x->whereNot('r.status', 'inquiry'))
            ->when($f->sourceId, fn ($x, $id) => $x->where('r.source_id', $id));
    }

    private function rows(Builder $q, ReportFilter $f, bool $export): array
    {
        return (clone $q)->leftJoin('booking_sources as bs', 'bs.id', '=', 'r.source_id')
            ->orderBy($f->basis === 'booked' ? 'r.created_at' : 'r.check_in', $f->basis === 'booked' ? 'desc' : 'asc')->orderByDesc('r.id')
            ->when(! $export, fn ($x) => $x->forPage($f->page, self::PER_PAGE), fn ($x) => $x->limit(self::EXPORT_LIMIT))
            ->get(['r.public_id', 'r.booking_ref', 'r.guest_name', 'r.status', 'bs.code as source_code', 'bs.name as source_name', 'r.created_at', 'r.check_in', 'r.check_out',
                'r.nights', 'r.room_count', 'r.adults', 'r.children', 'r.grand_total', 'r.paid_total'])
            ->map(function ($r) use ($f) {
                $booked = $this->local($r->created_at, $f);

                return [
                    'id' => $r->public_id, 'ref' => $r->booking_ref, 'guest' => $r->guest_name, 'status' => $r->status,
                    'source' => self::sourceName($r->source_code, $r->source_name), 'booked_on' => $booked, 'check_in' => (string) $r->check_in, 'check_out' => (string) $r->check_out,
                    'nights' => (int) $r->nights, 'rooms' => (int) $r->room_count, 'guests' => (int) $r->adults + (int) $r->children,
                    'grand_total' => Report::money($r->grand_total), 'paid' => Report::money($r->paid_total),
                    'lead_days' => max(0, (int) round((strtotime((string) $r->check_in) - strtotime($booked)) / 86400)),
                ];
            })->all();
    }

    private function reservations(int $propertyId): Builder
    {
        return DB::table('reservations as r')->where('r.property_id', $propertyId);
    }

    private function pagination(ReportFilter $f, int $total): array
    {
        return ['page' => $f->page, 'per_page' => self::PER_PAGE, 'total' => $total, 'last_page' => max(1, (int) ceil($total / self::PER_PAGE))];
    }

    private function local(?string $utc, ReportFilter $f): string
    {
        return $utc ? \Carbon\CarbonImmutable::parse($utc, 'UTC')->setTimezone($f->property->timezone ?: config('app.timezone'))->toDateString() : '';
    }
}
