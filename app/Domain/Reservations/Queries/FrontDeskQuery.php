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
 * Front desk: today's arrivals (including late ones still expected), in-house guests and
 * departures due today (or overdue). Index use: ix_res_arrivals / ix_res_status / ix_res_departures.
 */
class FrontDeskQuery
{
    public const TABS = ['arrivals', 'in_house', 'departures'];

    public function __construct(
        private readonly ReservationPresenter $presenter,
        private readonly UnitNightGuard $guard,
    ) {}

    public function list(Property $property, Request $request): array
    {
        $today = $this->guard->today($property);
        $tab = in_array($request->query('tab'), self::TABS, true) ? (string) $request->query('tab') : 'arrivals';
        $q = trim((string) $request->query('q', ''));

        $base = fn (string $t) => $this->scope(Reservation::query(), $t, $today)
            ->when($q !== '', fn (Builder $b) => $b->where(fn ($w) => $w->where('reservations.guest_name', 'like', SearchTerm::prefix($q))->orWhere('reservations.booking_ref', 'like', SearchTerm::prefix(strtoupper($q)))));
        $counts = [];
        foreach (self::TABS as $t) {
            $counts[$t] = $base($t)->count();
        }

        $rows = $base($tab)->with([
            'rooms:id,public_id,reservation_id,room_type_id,rate_plan_id,status,check_in,check_out,sort_order',
            'rooms.roomType:id,public_id,code,name', 'rooms.ratePlan:id,public_id,code,name',
            'source:id,code,name', 'primaryGuest:id,public_id,guest_no,first_name,last_name,email,phone_e164,nationality_iso2,is_vip',
        ]);
        $tab === 'departures' ? $rows->orderBy('reservations.check_out') : $rows->orderBy('reservations.check_in');
        $rows->orderBy('reservations.guest_name')->orderBy('reservations.id');

        $page = Listing::paginate($rows, $request);
        $units = $this->presenter->unitsFor(collect($page['rows'])->flatMap(fn (Reservation $r) => $r->rooms->pluck('id'))->all());
        $page['rows'] = array_map(fn (Reservation $r) => $this->presenter->row($r, $units, $today), $page['rows']);

        $rooms = DB::table('physical_units')->where('property_id', $property->id)->where('is_active', true)->whereNull('deleted_at')
            ->selectRaw("COUNT(*) AS total, SUM(housekeeping_status = 'dirty') AS dirty")->first();
        $occupied = DB::table('unit_nights')->where('property_id', $property->id)->where('stay_date', $today)->where('kind', 'reservation')->count();

        return $page + ['counts' => $counts, 'tab' => $tab, 'today' => $today, 'rooms' => [
            'total' => (int) ($rooms->total ?? 0), 'occupied' => $occupied, 'dirty' => (int) ($rooms->dirty ?? 0),
            'vacant' => max(0, (int) ($rooms->total ?? 0) - $occupied),
        ]];
    }

    private function scope(Builder $q, string $tab, string $today): Builder
    {
        return match ($tab) {
            'in_house' => $q->where('reservations.status', 'checked_in'),
            'departures' => $q->where('reservations.status', 'checked_in')->where('reservations.check_out', '<=', $today),
            default => $q->whereIn('reservations.status', ['pending', 'confirmed'])->where('reservations.check_in', '<=', $today)->where('reservations.check_out', '>', $today),
        };
    }
}
