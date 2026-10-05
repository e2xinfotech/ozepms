<?php

namespace App\Domain\Property;

use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * Figures for the property dashboard. It only reads the operational tables of
 * other modules, always filtered by the property id, and works while they are empty.
 */
class DashboardService
{
    private const CHART_DAYS_BEFORE = 6;

    private const CHART_DAYS_AFTER = 7;

    private const LIST_LIMIT = 5;

    public const MAX_RANGE_DAYS = 62;

    /**
     * @param  CarbonImmutable|null  $from  first day of the chart / revenue range (default: 6 days before today)
     * @param  CarbonImmutable|null  $to  last day of the range (default: 7 days after today)
     */
    public function build(Property $property, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $today = $this->today($property);
        $from ??= $today->subDays(self::CHART_DAYS_BEFORE);
        $to ??= $today->addDays(self::CHART_DAYS_AFTER);
        if ($to->lt($from) || $from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            $from = $today->subDays(self::CHART_DAYS_BEFORE);
            $to = $today->addDays(self::CHART_DAYS_AFTER);
        }
        $totalRooms = $this->units($property)->count();
        $occupied = $this->table('reservation_rooms', $property)->where('status', 'checked_in')->count();
        $blocks = $this->table('unit_blocks', $property)->whereNull('released_at')
            ->where('start_date', '<=', $today->toDateString())->where('end_date', '>', $today->toDateString())
            ->select('block_type', DB::raw('count(distinct unit_id) as total'))->groupBy('block_type')->pluck('total', 'block_type');
        $outOfService = (int) ($blocks['out_of_order'] ?? 0) + (int) ($blocks['maintenance'] ?? 0);
        $ownerHold = (int) ($blocks['owner_hold'] ?? 0);
        $blocked = $outOfService + $ownerHold;

        $arrivals = $this->table('reservations', $property)->where('check_in', $today->toDateString())
            ->whereIn('status', ['pending', 'confirmed', 'checked_in']);
        $departures = $this->table('reservations', $property)->where('check_out', $today->toDateString())
            ->whereIn('status', ['checked_in', 'checked_out']);
        $housekeeping = $this->units($property)->select('housekeeping_status', DB::raw('count(*) as total'))
            ->groupBy('housekeeping_status')->pluck('total', 'housekeeping_status');

        return [
            'today' => $today->toDateString(),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'currency' => $property->currency_code,
            'kpis' => [
                'total_rooms' => $totalRooms,
                'occupied' => $occupied,
                'occupancy' => $totalRooms > 0 ? round($occupied * 100 / $totalRooms, 1) : 0,
                'arrivals' => (clone $arrivals)->count(),
                'departures' => (clone $departures)->count(),
                'revenue' => $this->revenue($property, $from, $to),
            ],
            'chart' => $this->chart($property, $from, $to, $totalRooms),
            'room_status' => [
                'occupied' => $occupied,
                'vacant' => max(0, $totalRooms - $occupied - $blocked),
                'out_of_service' => $outOfService,
                'blocked' => $ownerHold,
                'total' => $totalRooms,
            ],
            'summary' => [
                'arrivals' => (clone $arrivals)->count(),
                'departures' => (clone $departures)->count(),
                'housekeeping' => (int) ($housekeeping['dirty'] ?? 0),
                'issues' => $blocked,
            ],
            'arrivals' => $this->reservationRows((clone $arrivals)->orderBy('reservations.guest_name')),
            'departures' => $this->reservationRows((clone $departures)->orderBy('reservations.guest_name')),
            'housekeeping' => [
                'clean' => (int) ($housekeeping['clean'] ?? 0),
                'dirty' => (int) ($housekeeping['dirty'] ?? 0),
                'inspected' => (int) ($housekeeping['inspected'] ?? 0),
            ],
            'checklist' => $this->checklist($property),
        ];
    }

    /**
     * Onboarding steps with links to the modules that exist in this installation.
     *
     * @return array<int, array{key: string, done: bool, url: ?string}>
     */
    public function checklist(Property $property): array
    {
        $count = fn (string $table) => $this->table($table, $property)->whereNull('deleted_at')->count();
        $link = fn (string $route) => Route::has($route) ? route($route, $property->code) : null;

        return [
            ['key' => 'step_property', 'done' => true, 'url' => $link('property.settings')],
            ['key' => 'step_rate_plan', 'done' => $count('rate_plans') > 0, 'url' => $link('property.rate-plans')],
            ['key' => 'step_room_types', 'done' => $count('room_types') > 0 && $count('physical_units') > 0, 'url' => $link('property.room-types')],
            ['key' => 'step_rates', 'done' => Schema::hasTable('ari_daily') && $this->table('ari_daily', $property)->exists(), 'url' => $link('property.calendar')],
            ['key' => 'step_users', 'done' => DB::table('property_users')->where('property_id', $property->id)->count() > 1, 'url' => $link('property.users')],
        ];
    }

    private function chart(Property $property, CarbonImmutable $from, CarbonImmutable $to, int $totalRooms): array
    {

        $nights = $this->table('reservation_room_nights', $property)
            ->where('is_active', true)
            ->whereBetween('stay_date', [$from->toDateString(), $to->toDateString()])
            ->select('stay_date', DB::raw('count(*) as rooms'), DB::raw('sum(net_price) as revenue'))
            ->groupBy('stay_date')
            ->get()
            ->keyBy(fn ($row) => substr((string) $row->stay_date, 0, 10));

        $days = [];
        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            $row = $nights[$d->toDateString()] ?? null;
            $rooms = (int) ($row->rooms ?? 0);
            $revenue = $this->decimal($row->revenue ?? '0');
            $days[] = [
                'date' => $d->toDateString(),
                'occupancy' => $totalRooms > 0 ? round($rooms * 100 / $totalRooms, 1) : 0,
                'revenue' => $revenue,
                'adr' => $rooms > 0 ? bcdiv($revenue, (string) $rooms, 2) : '0.00',
                'revpar' => $totalRooms > 0 ? bcdiv($revenue, (string) $totalRooms, 2) : '0.00',
            ];
        }

        return $days;
    }

    private function revenue(Property $property, CarbonImmutable $from, CarbonImmutable $to): string
    {
        $sum = $this->table('reservation_room_nights', $property)->where('is_active', true)
            ->whereBetween('stay_date', [$from->toDateString(), $to->toDateString()])
            ->sum('net_price');

        return $this->decimal((string) $sum);
    }

    private function reservationRows(Builder $query): array
    {
        return $query
            ->leftJoin('booking_sources', 'booking_sources.id', '=', 'reservations.source_id')
            ->limit(self::LIST_LIMIT)
            ->get(['reservations.public_id', 'reservations.booking_ref', 'reservations.guest_name', 'reservations.nights',
                'reservations.adults', 'reservations.children', 'reservations.status', 'booking_sources.name as source'])
            ->map(fn ($r) => [
                'id' => $r->public_id,
                'ref' => $r->booking_ref,
                'guest' => $r->guest_name,
                'nights' => (int) $r->nights,
                'guests' => (int) $r->adults + (int) $r->children,
                'status' => $r->status,
                'source' => $r->source,
            ])->all();
    }

    private function units(Property $property): Builder
    {
        return $this->table('physical_units', $property)->whereNull('deleted_at')->where('is_active', true);
    }

    private function table(string $table, Property $property): Builder
    {
        return DB::table($table)->where($table.'.property_id', $property->id);
    }

    private function today(Property $property): CarbonImmutable
    {
        return $property->business_date
            ? CarbonImmutable::parse($property->business_date->toDateString(), $property->timezone)
            : CarbonImmutable::now($property->timezone)->startOfDay();
    }

    private function decimal(string $value): string
    {
        return bcadd($value === '' ? '0' : $value, '0', 2);
    }
}
