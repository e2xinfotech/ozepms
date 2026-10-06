<?php

namespace App\Domain\Reservations\Queries;

use App\Domain\Accommodation\UnitNightGuard;
use App\Models\Property;
use App\Models\Reservation;
use App\Support\Listing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Reservations list v2 (design reservations-list-panel.png): KPI chips, filters, tabs and one
 * page of rows. Every filter maps to an index of docs/02-database.md:
 *   tabs/status → ix_res_status, arrivals → ix_res_arrivals, departures → ix_res_departures,
 *   group → ix_res_rooms, reference → uq_res_ref (prefix), guest name → ix_res_guest_name (prefix),
 *   phone → guest_phone snapshot, source → ix_res_source, dates → ix_res_arrivals / ix_res_checkin_date.
 * Room type / rate plan / PMS room filters use EXISTS on reservation_rooms / unit_nights indexes.
 */
class ReservationQuery
{
    public const TABS = ['all', 'confirmed', 'in_house', 'pending', 'arrivals', 'departures', 'cancelled', 'no_show', 'group', 'checked_out'];

    public const SORTS = [
        'ref' => 'reservations.booking_ref', 'guest' => 'reservations.guest_name', 'check_in' => 'reservations.check_in',
        'check_out' => 'reservations.check_out', 'nights' => 'reservations.nights', 'total' => 'reservations.grand_total',
        'created' => 'reservations.created_at', 'status' => 'reservations.status',
    ];

    public const FILTERS = ['q', 'status', 'source', 'from', 'to', 'date_field', 'room_type', 'rate_plan', 'unit', 'payment', 'tab', 'guest'];

    public function __construct(
        private readonly ReservationPresenter $presenter,
        private readonly UnitNightGuard $guard,
    ) {}

    /** @return array{rows: array, meta: array, counts: array<string, int>} */
    public function list(Property $property, Request $request): array
    {
        $filters = Listing::filters($request, self::FILTERS);
        $tab = in_array($filters['tab'], self::TABS, true) ? $filters['tab'] : 'all';
        $today = $this->guard->today($property);

        $filtered = $this->filtered($filters);
        $counts = $this->counts($filtered, $today);

        $rows = (clone $filtered);
        $this->applyTab($rows, $tab, $today);
        $rows->select('reservations.*')->with([
            'rooms:id,public_id,reservation_id,room_type_id,rate_plan_id,status,check_in,check_out,sort_order',
            'rooms.roomType:id,public_id,code,name',
            'rooms.ratePlan:id,public_id,code,name',
            'source:id,code,name',
            'primaryGuest:id,public_id,guest_no,first_name,last_name,email,phone_e164,nationality_iso2,is_vip',
        ]);
        Listing::sort($rows, $request, self::SORTS, 'reservations.created_at', 'desc');
        $rows->orderByDesc('reservations.id');

        $page = Listing::paginate($rows, $request);
        $units = $this->presenter->unitsFor(collect($page['rows'])->flatMap(fn (Reservation $r) => $r->rooms->pluck('id'))->all());
        $page['rows'] = array_map(fn (Reservation $r) => $this->presenter->row($r, $units, $today), $page['rows']);

        return $page + ['counts' => $counts];
    }

    /** Rows for the CSV export (same filters and tab), streamed in chunks by the controller. */
    public function exportQuery(Property $property, Request $request): Builder
    {
        $filters = Listing::filters($request, self::FILTERS);
        $tab = in_array($filters['tab'], self::TABS, true) ? $filters['tab'] : 'all';
        $query = $this->filtered($filters);
        $this->applyTab($query, $tab, $this->guard->today($property));

        return $query->select('reservations.*')->with(['source:id,code,name', 'rooms:id,reservation_id,room_type_id,rate_plan_id', 'rooms.roomType:id,code,name', 'rooms.ratePlan:id,code,name'])
            ->orderByDesc('reservations.created_at')->orderByDesc('reservations.id');
    }

    /** @param  array<string, string>  $f */
    private function filtered(array $f): Builder
    {
        $q = Reservation::query();

        if ($f['q'] !== '' && ($term = SearchTerm::parse($f['q'])) !== null) {
            $q->where(function (Builder $w) use ($term) {
                match ($term->kind) {
                    'ref' => $w->where('reservations.booking_ref', 'like', SearchTerm::prefix($term->value)),
                    'email' => $w->whereIn('reservations.primary_guest_id', DB::table('guests')->where('property_id', $this->propertyId())
                        ->where('email_lc', 'like', SearchTerm::prefix($term->value))->select('id')),
                    'phone' => $w->whereIn('reservations.primary_guest_id', DB::table('guests')->where('property_id', $this->propertyId())
                        ->where('phone_rev', 'like', SearchTerm::prefix(strrev($term->value)))->select('id')),
                    default => $w->where('reservations.guest_name', 'like', SearchTerm::prefix($term->value))
                        ->orWhere('reservations.booking_ref', $term->value)->orWhere('reservations.channel_ref', $term->value),
                };
                if ($term->room !== null) {
                    // PMS room number: reservations with a night in that room.
                    $w->orWhereExists(fn ($e) => $e->from('unit_nights')->join('reservation_rooms as rr_u', 'rr_u.id', '=', 'unit_nights.reservation_room_id')
                        ->join('physical_units as pu_q', 'pu_q.id', '=', 'unit_nights.unit_id')
                        ->whereColumn('rr_u.reservation_id', 'reservations.id')->where('pu_q.property_id', $this->propertyId())->where('pu_q.name', $term->room));
                }
            });
        }
        if ($f['status'] !== '' && in_array($f['status'], Reservation::STATUSES, true)) {
            $q->where('reservations.status', $f['status']);
        }
        if ($f['source'] !== '') {
            $q->whereIn('reservations.source_id', DB::table('booking_sources')->where('code', $f['source'])->select('id'));
        }
        if ($f['payment'] !== '' && in_array($f['payment'], ['unpaid', 'partial', 'paid', 'refunded'], true)) {
            $q->where('reservations.payment_status', $f['payment']);
        }
        $field = in_array($f['date_field'], ['check_in', 'check_out', 'stay', 'created'], true) ? $f['date_field'] : 'stay';
        $from = $this->date($f['from']);
        $to = $this->date($f['to']);
        if ($from !== null || $to !== null) {
            match ($field) {
                // Stays touching the range: arrival before its end and departure after its start.
                'stay' => $q->when($to, fn ($x) => $x->where('reservations.check_in', '<=', $to))->when($from, fn ($x) => $x->where('reservations.check_out', '>', $from)),
                'created' => $q->when($from, fn ($x) => $x->where('reservations.created_at', '>=', $from.' 00:00:00'))->when($to, fn ($x) => $x->where('reservations.created_at', '<=', $to.' 23:59:59')),
                default => $q->when($from, fn ($x) => $x->where('reservations.'.$field, '>=', $from))->when($to, fn ($x) => $x->where('reservations.'.$field, '<=', $to)),
            };
        }
        if ($f['room_type'] !== '') {
            $q->whereExists(fn ($e) => $e->from('reservation_rooms as rr_t')->join('room_types as rt_f', 'rt_f.id', '=', 'rr_t.room_type_id')
                ->whereColumn('rr_t.reservation_id', 'reservations.id')->where('rt_f.public_id', $f['room_type']));
        }
        if ($f['rate_plan'] !== '') {
            $q->whereExists(fn ($e) => $e->from('reservation_rooms as rr_p')->join('rate_plans as rp_f', 'rp_f.id', '=', 'rr_p.rate_plan_id')
                ->whereColumn('rr_p.reservation_id', 'reservations.id')->where('rp_f.public_id', $f['rate_plan']));
        }
        if ($f['unit'] !== '') {
            $q->whereExists(fn ($e) => $e->from('unit_nights')->join('reservation_rooms as rr_n', 'rr_n.id', '=', 'unit_nights.reservation_room_id')
                ->join('physical_units as pu_f', 'pu_f.id', '=', 'unit_nights.unit_id')
                ->whereColumn('rr_n.reservation_id', 'reservations.id')->where('pu_f.public_id', $f['unit']));
        }
        if ($f['guest'] !== '') {
            $q->whereIn('reservations.primary_guest_id', DB::table('guests')->where('public_id', $f['guest'])->select('id'));
        }

        return $q;
    }

    private function applyTab(Builder $q, string $tab, string $today): void
    {
        match ($tab) {
            'confirmed' => $q->where('reservations.status', 'confirmed'),
            'in_house' => $q->where('reservations.status', 'checked_in'),
            'pending' => $q->whereIn('reservations.status', ['pending', 'inquiry', 'hold']),
            'arrivals' => $q->where('reservations.check_in', $today)->whereIn('reservations.status', ['pending', 'confirmed', 'checked_in']),
            'departures' => $q->where('reservations.check_out', $today)->whereIn('reservations.status', ['checked_in', 'checked_out']),
            'cancelled' => $q->where('reservations.status', 'cancelled'),
            'no_show' => $q->where('reservations.status', 'no_show'),
            'checked_out' => $q->where('reservations.status', 'checked_out'),
            'group' => $q->where('reservations.room_count', '>', 1),
            default => null,
        };
    }

    /** KPI chips and tab counts: one grouped index scan + three small counts. */
    private function counts(Builder $filtered, string $today): array
    {
        $byStatus = (clone $filtered)->toBase()->select('reservations.status', DB::raw('COUNT(*) AS c'))
            ->groupBy('reservations.status')->pluck('c', 'status')->map(fn ($v) => (int) $v);
        $arrivals = (clone $filtered)->where('reservations.check_in', $today)->whereIn('reservations.status', ['pending', 'confirmed', 'checked_in'])->count();
        $departures = (clone $filtered)->where('reservations.check_out', $today)->whereIn('reservations.status', ['checked_in', 'checked_out'])->count();
        $group = (clone $filtered)->where('reservations.room_count', '>', 1)->count();

        return [
            'all' => $byStatus->sum(),
            'confirmed' => $byStatus['confirmed'] ?? 0,
            'in_house' => $byStatus['checked_in'] ?? 0,
            'pending' => ($byStatus['pending'] ?? 0) + ($byStatus['inquiry'] ?? 0) + ($byStatus['hold'] ?? 0),
            'arrivals' => $arrivals,
            'departures' => $departures,
            'cancelled' => $byStatus['cancelled'] ?? 0,
            'no_show' => $byStatus['no_show'] ?? 0,
            'checked_out' => $byStatus['checked_out'] ?? 0,
            'group' => $group,
        ];
    }

    private function propertyId(): int
    {
        return app(\App\Support\PropertyContext::class)->id();
    }

    private function date(string $value): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
