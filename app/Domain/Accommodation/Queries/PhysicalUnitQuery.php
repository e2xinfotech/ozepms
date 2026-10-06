<?php

namespace App\Domain\Accommodation\Queries;

use App\Domain\Accommodation\UnitNightGuard;
use App\Models\PhysicalUnit;
use App\Support\Listing;
use App\Support\PropertyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * PMS rooms list with today's status:
 *   inactive        room switched off
 *   occupied        a guest is assigned to the room tonight (unit_nights)
 *   out_of_order    an out-of-order block covers tonight
 *   out_of_service  a maintenance / owner block covers tonight
 *   available       otherwise
 * Housekeeping tabs: "housekeeping" = dirty rooms, "due_cleaning" = not cleaned in the last 24 hours.
 * unit_nights and reservations belong to the reservations module and are read only.
 */
class PhysicalUnitQuery
{
    public const TABS = ['all', 'available', 'occupied', 'out_of_service', 'out_of_order', 'housekeeping', 'due_cleaning', 'inactive'];

    public const SORTS = [
        'name' => 'physical_units.name', 'room_type' => 'room_types.name', 'floor' => 'physical_units.floor',
        'housekeeping' => 'physical_units.housekeeping_status', 'order' => 'physical_units.sort_order',
    ];

    public function __construct(
        private readonly PropertyContext $context,
        private readonly UnitNightGuard $guard,
    ) {}

    public function today(): string
    {
        return $this->guard->today($this->context->property());
    }

    /** @return array{rows: array, meta: array, counts: array} */
    public function list(Request $request): array
    {
        $filters = Listing::filters($request, ['q', 'room_type', 'status', 'floor', 'tab']);
        $tab = in_array($filters['tab'], self::TABS, true) ? $filters['tab'] : 'all';
        $today = $this->today();
        [$statusSql, $statusBindings] = $this->statusSql($today);

        $base = $this->base($today)
            ->when($filters['q'] !== '', function (Builder $q) use ($filters) {
                $term = '%'.$filters['q'].'%';
                $q->where(fn (Builder $w) => $w->where('physical_units.name', 'like', $term)
                    ->orWhere('physical_units.notes', 'like', $term)
                    ->orWhere('room_types.name', 'like', $term));
            })
            ->when($filters['room_type'] !== '', fn (Builder $q) => $q->where('room_types.public_id', $filters['room_type']))
            ->when($filters['floor'] !== '', fn (Builder $q) => $q->where('physical_units.floor', $filters['floor']))
            ->when($filters['status'] !== '', fn (Builder $q) => $q->whereRaw("($statusSql) = ?", [...$statusBindings, $filters['status']]));

        $counts = $this->counts(clone $base, $statusSql, $statusBindings, $today);

        $rows = (clone $base)
            ->select([
                'physical_units.*', 'room_types.public_id as rt_public_id', 'room_types.name as rt_name', 'room_types.code as rt_code',
                'reservations.public_id as res_public_id', 'reservations.booking_ref as res_ref', 'reservations.guest_name as res_guest',
            ])
            ->selectRaw("($statusSql) AS room_status", $statusBindings);
        $this->applyTab($rows, $tab, $statusSql, $statusBindings);

        // Default order: room types in their display order, then each type's rooms (101, 102, …).
        Listing::sort($rows, $request, self::SORTS, 'room_types.sort_order');
        $rows->orderBy('room_types.sort_order')->orderBy('physical_units.sort_order')->orderBy('physical_units.name');

        return Listing::paginate($rows, $request, fn (PhysicalUnit $u) => $this->row($u)) + ['counts' => $counts, 'today' => $today];
    }

    /** One room with its live status (side panel). */
    public function find(string $publicId): PhysicalUnit
    {
        $today = $this->today();
        [$statusSql, $statusBindings] = $this->statusSql($today);

        return $this->base($today)
            ->where('physical_units.public_id', $publicId)
            ->select([
                'physical_units.*', 'room_types.public_id as rt_public_id', 'room_types.name as rt_name', 'room_types.code as rt_code',
                'reservations.public_id as res_public_id', 'reservations.booking_ref as res_ref', 'reservations.guest_name as res_guest',
            ])
            ->selectRaw("($statusSql) AS room_status", $statusBindings)
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    public function row(PhysicalUnit $u): array
    {
        return [
            'id' => $u->public_id,
            'name' => $u->name,
            'floor' => $u->floor,
            'building' => $u->building,
            'room_type' => ['id' => $u->rt_public_id, 'name' => $u->rt_name, 'code' => $u->rt_code],
            'status' => $u->room_status,
            'housekeeping_status' => $u->housekeeping_status,
            'last_cleaned_at' => $u->last_cleaned_at?->toIso8601String(),
            'is_active' => $u->is_active,
            'guest' => $u->res_ref ? ['name' => $u->res_guest, 'reservation' => $u->res_ref, 'reservation_id' => $u->res_public_id] : null,
        ];
    }

    private function base(string $today): Builder
    {
        return PhysicalUnit::query()
            ->join('room_types', 'room_types.id', '=', 'physical_units.room_type_id')
            ->leftJoin('unit_nights', function ($join) use ($today) {
                $join->on('unit_nights.unit_id', '=', 'physical_units.id')
                    ->where('unit_nights.stay_date', '=', $today)
                    ->where('unit_nights.kind', '=', 'reservation');
            })
            ->leftJoin('reservation_rooms', 'reservation_rooms.id', '=', 'unit_nights.reservation_room_id')
            ->leftJoin('reservations', 'reservations.id', '=', 'reservation_rooms.reservation_id');
    }

    /** @return array{0: string, 1: list<string>} */
    private function statusSql(string $today): array
    {
        $block = 'EXISTS (SELECT 1 FROM unit_blocks b WHERE b.unit_id = physical_units.id AND b.released_at IS NULL'
            .' AND b.start_date <= ? AND b.end_date > ? AND b.block_type %s)';

        $sql = 'CASE WHEN physical_units.is_active = 0 THEN \'inactive\''
            .' WHEN unit_nights.unit_id IS NOT NULL THEN \'occupied\''
            .' WHEN '.sprintf($block, "= 'out_of_order'").' THEN \'out_of_order\''
            .' WHEN '.sprintf($block, "<> 'out_of_order'").' THEN \'out_of_service\''
            .' ELSE \'available\' END';

        return [$sql, [$today, $today, $today, $today]];
    }

    private function applyTab(Builder $query, string $tab, string $statusSql, array $bindings): void
    {
        match ($tab) {
            'all' => null,
            'housekeeping' => $query->where('physical_units.is_active', true)->where('physical_units.housekeeping_status', 'dirty'),
            'due_cleaning' => $query->where('physical_units.is_active', true)->where(fn (Builder $q) => $q
                ->whereNull('physical_units.last_cleaned_at')
                ->orWhere('physical_units.last_cleaned_at', '<', Carbon::now()->subDay())),
            default => $query->whereRaw("($statusSql) = ?", [...$bindings, $tab]),
        };
    }

    /** @return array<string, int> */
    private function counts(Builder $base, string $statusSql, array $bindings, string $today): array
    {
        $dayAgo = Carbon::now()->subDay()->toDateTimeString();
        $row = $base->toBase()->selectRaw(
            "COUNT(*) AS total,
             SUM(($statusSql) = 'available') AS available,
             SUM(($statusSql) = 'occupied') AS occupied,
             SUM(($statusSql) = 'out_of_service') AS out_of_service,
             SUM(($statusSql) = 'out_of_order') AS out_of_order,
             SUM(($statusSql) = 'inactive') AS inactive,
             SUM(physical_units.is_active = 1 AND physical_units.housekeeping_status = 'dirty') AS housekeeping,
             SUM(physical_units.is_active = 1 AND (physical_units.last_cleaned_at IS NULL OR physical_units.last_cleaned_at < ?)) AS due_cleaning",
            [...$bindings, ...$bindings, ...$bindings, ...$bindings, ...$bindings, $dayAgo],
        )->first();

        $out = ['all' => (int) ($row->total ?? 0)];
        foreach (array_slice(self::TABS, 1) as $tab) {
            $out[$tab] = (int) ($row->{$tab} ?? 0);
        }

        return $out;
    }
}
