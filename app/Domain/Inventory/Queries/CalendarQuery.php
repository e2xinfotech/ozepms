<?php

namespace App\Domain\Inventory\Queries;

use App\Domain\Rates\DerivedPrice;
use App\Models\Property;
use App\Models\RoomTypeImage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/**
 * Read side of the calendar: one window (normally 14 or 31 nights) for one property.
 *
 *   room type ▸ availability per night (inventory_daily)
 *             ▸ products (room type × rate plan) with price, occupancy prices and restrictions (ari_daily)
 *             ▸ PMS rooms with occupancy bars (unit_nights + reservations) and unit blocks
 *
 * A fixed number of indexed queries regardless of the size of the property (room types, products,
 * rooms, images, inventory range, ARI range, occupancy range, blocks, room nights, tonight's
 * occupancy); everything else is assembled in memory. Days without a stored row fall back to the
 * defaults the booking engine would use (active rooms, product default price, rate plan stay rules)
 * and are flagged with "d" so the page can show them muted.
 */
final class CalendarQuery
{
    public const MAX_DAYS = 62;

    /** Filter values for "status". "" = active records only. */
    public const STATUSES = ['all', 'active', 'inactive', 'sold_out', 'stop_sell', 'restricted'];

    /** Window lengths: a calendar month, or two weeks from the chosen date. */
    public const RANGES = ['month', 'week'];

    public const WEEK_DAYS = 14;

    /**
     * First night and number of nights for a range ("month" | "week") around a date.
     * A month always starts on the 1st; two weeks start on the date itself.
     *
     * @return array{0: CarbonImmutable, 1: int}
     */
    public static function resolveWindow(string $range, ?string $date, Property $property): array
    {
        $tz = $property->timezone ?: 'UTC';
        $start = null;
        if ($date !== null && preg_match('/^\d{4}-\d{2}(-\d{2})?$/', $date)) {
            try {
                $start = CarbonImmutable::createFromFormat('!Y-m-d', strlen($date) === 7 ? $date.'-01' : $date);
            } catch (\Throwable) {
                $start = null;
            }
        }
        $start = $start ?: CarbonImmutable::createFromFormat('!Y-m-d', CarbonImmutable::now($tz)->toDateString());

        return $range === 'week'
            ? [$start, self::WEEK_DAYS]
            : [$start->startOfMonth(), (int) $start->daysInMonth];
    }

    /**
     * @param  array{room_type?: ?string, unit?: ?string, rate_plan?: ?string, status?: ?string}  $filters  public ids / status key
     * @return array<string, mixed>
     */
    public function window(Property $property, CarbonImmutable $from, int $days, array $filters = []): array
    {
        $days = max(1, min(self::MAX_DAYS, $days));
        $from = $from->startOfDay();
        $to = $from->addDays($days);                     // exclusive
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();
        $today = CarbonImmutable::now($property->timezone ?: 'UTC')->toDateString();
        $status = in_array($filters['status'] ?? '', self::STATUSES, true) ? (string) $filters['status'] : '';
        $withInactive = in_array($status, ['all', 'inactive'], true);
        $pid = (int) $property->id;

        $dates = [];
        for ($d = $from; $d < $to; $d = $d->addDay()) {
            $dates[] = $d->toDateString();
        }
        $index = array_flip($dates);

        // 1. Room types
        $roomTypes = DB::table('room_types')
            ->where('property_id', $pid)->whereNull('deleted_at')
            ->when(! $withInactive, fn ($q) => $q->where('is_active', 1))
            ->when(! empty($filters['room_type']), fn ($q) => $q->where('public_id', $filters['room_type']))
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'public_id', 'code', 'name', 'base_adults', 'max_adults', 'is_active']);

        // 2. PMS rooms (all room types of the property; a unit filter narrows the room types too)
        $units = DB::table('physical_units')
            ->where('property_id', $pid)->whereNull('deleted_at')
            ->when(! $withInactive, fn ($q) => $q->where('is_active', 1))
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'public_id', 'room_type_id', 'name', 'floor', 'housekeeping_status', 'is_active']);
        $activeUnitCount = [];
        foreach ($units as $u) {
            if ($u->is_active) {
                $activeUnitCount[$u->room_type_id] = ($activeUnitCount[$u->room_type_id] ?? 0) + 1;
            }
        }
        if (! empty($filters['unit'])) {
            $unit = $units->firstWhere('public_id', $filters['unit']);
            $units = $unit ? collect([$unit]) : collect();
            $roomTypes = $roomTypes->where('id', $unit->room_type_id ?? 0)->values();
        }
        $roomTypeIds = $roomTypes->pluck('id')->all();

        // 3. Products with their rate plan and meal plan
        $products = $roomTypeIds === [] ? collect() : DB::table('room_type_rate_plans as p')
            ->join('rate_plans as rp', 'rp.id', '=', 'p.rate_plan_id')
            ->leftJoin('meal_plans as mp', 'mp.id', '=', 'rp.meal_plan_id')
            ->where('p.property_id', $pid)->whereIn('p.room_type_id', $roomTypeIds)->whereNull('rp.deleted_at')
            ->when(! $withInactive, fn ($q) => $q->where('p.is_active', 1)->where('rp.is_active', 1))
            ->orderBy('p.sort_order')->orderBy('rp.sort_order')->orderBy('rp.name')
            ->get([
                'p.id', 'p.public_id', 'p.room_type_id', 'p.rate_plan_id', 'p.pricing_mode', 'p.parent_product_id', 'p.adjust_type',
                'p.adjust_value', 'p.inherit_restrictions', 'p.default_price', 'p.is_active',
                'rp.public_id as rp_public_id', 'rp.code as rp_code', 'rp.name as rp_name', 'rp.is_active as rp_active',
                'rp.default_min_los', 'rp.default_max_los', 'rp.min_advance_days', 'rp.max_advance_days',
                'mp.code as meal_code',
            ]);
        // Parents of derived products may sit outside the filter (another rate plan); they are needed for prices.
        $byId = $products->keyBy('id')->all();
        $missingParents = $products->pluck('parent_product_id')->filter()->reject(fn ($id) => isset($byId[$id]))->unique()->values()->all();
        if ($missingParents !== []) {
            $parents = DB::table('room_type_rate_plans as p')
                ->join('rate_plans as rp', 'rp.id', '=', 'p.rate_plan_id')
                ->where('p.property_id', $pid)->whereIn('p.id', $missingParents)
                ->get(['p.id', 'p.room_type_id', 'p.pricing_mode', 'p.parent_product_id', 'p.adjust_type', 'p.adjust_value', 'p.inherit_restrictions', 'p.default_price',
                    'rp.code as rp_code', 'rp.name as rp_name', 'rp.default_min_los', 'rp.default_max_los', 'rp.min_advance_days', 'rp.max_advance_days']);
            foreach ($parents as $p) {
                $byId[$p->id] = $p;
            }
        }
        $productIds = array_keys($byId);
        $shown = $products;
        if (! empty($filters['rate_plan'])) {
            $shown = $products->where('rp_public_id', $filters['rate_plan'])->values();
        }

        // 4. Daily inventory, 5. daily ARI, 6. occupancy prices — clustered/property-date index range scans
        $inventory = [];
        if ($roomTypeIds !== []) {
            foreach (DB::table('inventory_daily')->where('property_id', $pid)->where('stay_date', '>=', $fromDate)->where('stay_date', '<', $toDate)
                ->whereIn('room_type_id', $roomTypeIds)
                ->get(['room_type_id', 'stay_date', 'total_units', 'ooo_units', 'sold', 'held', 'sell_limit', 'stop_sell']) as $row) {
                $inventory[$row->room_type_id][$row->stay_date] = $row;
            }
        }
        $ari = [];
        $occupancy = [];
        if ($productIds !== []) {
            foreach (DB::table('ari_daily')->where('property_id', $pid)->where('stay_date', '>=', $fromDate)->where('stay_date', '<', $toDate)
                ->whereIn('product_id', $productIds)
                ->get(['product_id', 'stay_date', 'price', 'min_los', 'max_los', 'min_los_arrival', 'cta', 'ctd', 'stop_sell', 'cutoff_days', 'max_advance_days']) as $row) {
                $ari[$row->product_id][$row->stay_date] = $row;
            }
            foreach (DB::table('ari_daily_occupancy')->where('property_id', $pid)->where('stay_date', '>=', $fromDate)->where('stay_date', '<', $toDate)
                ->whereIn('product_id', $productIds)->orderBy('adults')
                ->get(['product_id', 'stay_date', 'adults', 'price']) as $row) {
                $occupancy[$row->product_id][$row->stay_date][(string) $row->adults] = (string) $row->price;
            }
        }

        // 7. Unit blocks overlapping the window or tonight
        $blockFrom = min($fromDate, $today);
        $blockTo = max($toDate, CarbonImmutable::parse($today)->addDay()->toDateString());
        $blocks = DB::table('unit_blocks')->where('property_id', $pid)->whereNull('released_at')
            ->where('start_date', '<', $blockTo)->where('end_date', '>', $blockFrom)
            ->orderBy('start_date')
            ->get(['id', 'unit_id', 'block_type', 'start_date', 'end_date', 'reason']);

        // 8. Room nights in the window (tape chart index) with their reservation
        $nights = DB::table('unit_nights as un')
            ->join('reservation_rooms as rr', 'rr.id', '=', 'un.reservation_room_id')
            ->join('reservations as r', 'r.id', '=', 'rr.reservation_id')
            ->where('un.property_id', $pid)->where('un.kind', 'reservation')
            ->where('un.stay_date', '>=', $fromDate)->where('un.stay_date', '<', $toDate)
            ->whereNotIn('rr.status', ['cancelled', 'no_show'])
            ->orderBy('un.unit_id')->orderBy('un.stay_date')
            ->get(['un.unit_id', 'un.stay_date', 'un.reservation_room_id', 'rr.status', 'rr.check_in', 'rr.check_out', 'rr.adults', 'rr.children',
                'r.public_id', 'r.booking_ref', 'r.guest_name']);

        // 9. Who is in each room tonight (for the room status badge; tonight may be outside the window)
        $occupiedTonight = $today >= $fromDate && $today < $toDate
            ? $nights->where('stay_date', $today)->pluck('unit_id')->flip()->all()
            : DB::table('unit_nights')->where('property_id', $pid)->where('stay_date', $today)->where('kind', 'reservation')->pluck('unit_id')->flip()->all();

        // 10. First image of each room type
        $images = $roomTypeIds === [] ? collect() : DB::table('room_type_images')->where('property_id', $pid)->whereIn('room_type_id', $roomTypeIds)
            ->orderBy('sort_order')->orderBy('id')->get(['room_type_id', 'path'])->unique('room_type_id')->keyBy('room_type_id');

        // ---- Assemble -------------------------------------------------------------------------
        $blocksByUnit = $blocks->groupBy('unit_id');
        $nightsByUnit = $nights->groupBy('unit_id');
        $unitsByType = $units->groupBy('room_type_id');
        $productsByType = $shown->groupBy('room_type_id');
        $reservationUrl = Route::has('property.reservations.show');

        // Per room type & night: rooms blocked / sold according to the room-level data (fallback for missing inventory rows)
        $blockedPerNight = [];
        $soldPerNight = [];
        $unitType = $units->pluck('room_type_id', 'id')->all();
        foreach ($blocks as $b) {
            if (! isset($unitType[$b->unit_id])) {
                continue;
            }
            foreach ($dates as $date) {
                if ($date >= $b->start_date && $date < $b->end_date) {
                    $blockedPerNight[$unitType[$b->unit_id]][$date] = ($blockedPerNight[$unitType[$b->unit_id]][$date] ?? 0) + 1;
                }
            }
        }
        foreach ($nights as $n) {
            if (isset($unitType[$n->unit_id])) {
                $soldPerNight[$unitType[$n->unit_id]][$n->stay_date] = ($soldPerNight[$unitType[$n->unit_id]][$n->stay_date] ?? 0) + 1;
            }
        }

        $resolved = [];  // product id => list of day arrays (memo for derived chains)
        $resolve = function (int $productId) use (&$resolve, &$resolved, $byId, $ari, $occupancy, $dates, $roomTypes): array {
            if (isset($resolved[$productId])) {
                return $resolved[$productId];
            }
            $resolved[$productId] = [];   // breaks accidental cycles
            $p = $byId[$productId] ?? null;
            if (! $p) {
                return [];
            }
            $parentDays = $p->pricing_mode === 'derived' && $p->parent_product_id ? $resolve((int) $p->parent_product_id) : null;
            $persons = (int) ($roomTypes->firstWhere('id', $p->room_type_id)->base_adults ?? 1) ?: 1;
            $out = [];
            foreach ($dates as $i => $date) {
                $row = $ari[$productId][$date] ?? null;
                $default = $row === null;
                if ($p->pricing_mode === 'derived') {
                    $parentPrice = $parentDays[$i]['p'] ?? null;
                    $price = $parentPrice === null ? null : DerivedPrice::apply($parentPrice, (string) $p->adjust_type, (string) $p->adjust_value, $persons);
                    $default = $parentDays[$i]['d'] ?? true;
                } else {
                    $stored = $row !== null && $row->price !== null;
                    $price = $stored ? (string) $row->price : ($p->default_price !== null ? (string) $p->default_price : null);
                    $default = ! $stored;
                }
                $inherit = $p->pricing_mode === 'derived' && $p->inherit_restrictions && $parentDays !== null;
                if ($inherit) {
                    $r = $parentDays[$i];
                    $day = ['min' => $r['min'], 'max' => $r['max'], 'mla' => $r['mla'], 'cta' => $r['cta'], 'ctd' => $r['ctd'], 'ss' => $r['ss'], 'cut' => $r['cut'], 'adv' => $r['adv']];
                    // A product can still be closed on its own even when it follows the parent's restrictions.
                    $day['ss'] = $day['ss'] || (bool) ($row->stop_sell ?? false);
                } else {
                    $day = [
                        'min' => $row !== null && $row->min_los !== null ? (int) $row->min_los : (int) ($p->default_min_los ?? 1),
                        'max' => $row !== null && $row->max_los !== null ? (int) $row->max_los : ($p->default_max_los !== null ? (int) $p->default_max_los : null),
                        'mla' => $row !== null && $row->min_los_arrival !== null ? (int) $row->min_los_arrival : null,
                        'cta' => (bool) ($row->cta ?? false),
                        'ctd' => (bool) ($row->ctd ?? false),
                        'ss' => (bool) ($row->stop_sell ?? false),
                        'cut' => $row !== null && $row->cutoff_days !== null ? (int) $row->cutoff_days : ($p->min_advance_days !== null ? (int) $p->min_advance_days : null),
                        'adv' => $row !== null && $row->max_advance_days !== null ? (int) $row->max_advance_days : ($p->max_advance_days !== null ? (int) $p->max_advance_days : null),
                    ];
                }
                $out[] = ['p' => $price, 'o' => $occupancy[$productId][$date] ?? null, 'd' => $default, 'i' => $inherit] + $day;
            }

            return $resolved[$productId] = $out;
        };

        $rows = [];
        foreach ($roomTypes as $rt) {
            $typeUnits = $unitsByType->get($rt->id, collect());
            $total = $activeUnitCount[$rt->id] ?? 0;

            $inv = [];
            foreach ($dates as $date) {
                $row = $inventory[$rt->id][$date] ?? null;
                $t = $row ? (int) $row->total_units : $total;
                $ooo = $row ? (int) $row->ooo_units : min($t, $blockedPerNight[$rt->id][$date] ?? 0);
                $sold = $row ? (int) $row->sold : min($t, $soldPerNight[$rt->id][$date] ?? 0);
                $held = $row ? (int) $row->held : 0;
                $limit = $row && $row->sell_limit !== null ? (int) $row->sell_limit : null;
                $inv[] = [
                    't' => $t, 's' => $sold, 'h' => $held, 'o' => $ooo, 'l' => $limit,
                    'a' => max(0, min($t, $limit ?? $t) - $ooo - $sold - $held),
                    'ss' => (bool) ($row->stop_sell ?? false),
                    'd' => $row === null,
                ];
            }

            $productRows = [];
            foreach ($productsByType->get($rt->id, collect()) as $p) {
                $productRows[] = [
                    'id' => $p->public_id,
                    'rate_plan' => ['id' => $p->rp_public_id, 'code' => $p->rp_code, 'name' => $p->rp_name],
                    'meal_plan' => $p->meal_code,
                    'pricing_mode' => $p->pricing_mode,
                    'parent' => $p->pricing_mode === 'derived' && isset($byId[$p->parent_product_id])
                        ? $byId[$p->parent_product_id]->rp_name.' ('.$byId[$p->parent_product_id]->rp_code.')' : null,
                    'inherits_restrictions' => $p->pricing_mode === 'derived' && (bool) $p->inherit_restrictions,
                    'is_active' => (bool) $p->is_active && (bool) $p->rp_active,
                    'base_adults' => (int) $rt->base_adults,
                    'days' => $resolve((int) $p->id),
                ];
            }

            $unitRows = [];
            foreach ($typeUnits as $u) {
                $unitRows[] = [
                    'id' => $u->public_id,
                    'name' => $u->name,
                    'floor' => $u->floor,
                    'is_active' => (bool) $u->is_active,
                    'housekeeping' => $u->housekeeping_status,
                    'status' => $this->unitStatus($u, $today, $blocksByUnit->get($u->id, collect()), isset($occupiedTonight[$u->id])),
                    'bars' => array_merge(
                        $this->reservationBars($nightsByUnit->get($u->id, collect()), $index, $property->code, $reservationUrl),
                        $this->blockBars($blocksByUnit->get($u->id, collect()), $fromDate, $toDate, $index),
                    ),
                ];
            }

            $names = $typeUnits->where('is_active', true)->pluck('name')->all();
            $rows[] = [
                'id' => $rt->public_id,
                'code' => $rt->code,
                'name' => $rt->name,
                'is_active' => (bool) $rt->is_active,
                'image' => isset($images[$rt->id]) ? Storage::disk(RoomTypeImage::DISK)->url($images[$rt->id]->path) : null,
                'units_count' => $total,
                'units_range' => $names === [] ? null : (count($names) === 1 ? $names[0] : reset($names).' – '.end($names)),
                'max_adults' => (int) $rt->max_adults,
                'inventory' => $inv,
                'products' => $productRows,
                'units' => $unitRows,
            ];
        }

        $rows = $this->applyStatusFilter($rows, $status);

        return [
            'from' => $fromDate,
            'to' => $to->subDay()->toDateString(),
            'today' => $today,
            'days' => array_map(function (string $date) {
                $d = CarbonImmutable::parse($date);

                return ['date' => $date, 'day' => (int) $d->format('j'), 'dow' => (int) $d->isoWeekday(), 'weekend' => $d->isWeekend()];
            }, $dates),
            'currency' => $property->currency_code,
            'room_types' => $rows,
        ];
    }

    /** Same rule as the PMS rooms list: inactive, occupied, out of order, out of service, available. */
    private function unitStatus(object $unit, string $today, $blocks, bool $occupied): string
    {
        if (! $unit->is_active) {
            return 'inactive';
        }
        if ($occupied) {
            return 'occupied';
        }
        $tonight = $blocks->first(fn ($b) => $b->start_date <= $today && $b->end_date > $today);

        return $tonight ? ($tonight->block_type === 'out_of_order' ? 'out_of_order' : 'out_of_service') : 'available';
    }

    /**
     * Consecutive nights of the same reservation room in one PMS room become one bar.
     * "start"/"end" are day indexes in the window (end inclusive); "cont_*" mark bars cut by the window edge.
     *
     * @return list<array<string, mixed>>
     */
    private function reservationBars($nights, array $index, string $propertyCode, bool $withUrl): array
    {
        $bars = [];
        $current = null;
        foreach ($nights as $n) {
            $i = $index[$n->stay_date] ?? null;
            if ($i === null) {
                continue;
            }
            if ($current && $current['rr'] === $n->reservation_room_id && $current['end'] === $i - 1) {
                $current['end'] = $i;

                continue;
            }
            if ($current) {
                $bars[] = $current;
            }
            $current = [
                'rr' => $n->reservation_room_id,
                'kind' => 'reservation',
                'start' => $i,
                'end' => $i,
                'status' => $n->status === 'checked_in' ? 'in_house' : (in_array($n->status, ['hold', 'pending'], true) ? 'pending' : $n->status),
                'guest' => $n->guest_name,
                'reference' => $n->booking_ref,
                'check_in' => $n->check_in,
                'check_out' => $n->check_out,
                'adults' => (int) $n->adults,
                'children' => (int) $n->children,
                'url' => $withUrl ? route('property.reservations.show', ['property' => $propertyCode, 'reservation' => $n->public_id]) : null,
            ];
        }
        if ($current) {
            $bars[] = $current;
        }
        $dates = array_keys($index);

        return array_map(function (array $b) use ($dates) {
            unset($b['rr']);
            $b['cont_before'] = $b['check_in'] < $dates[$b['start']];
            $b['cont_after'] = $b['check_out'] > CarbonImmutable::parse($dates[$b['end']])->addDay()->toDateString();

            return $b;
        }, $bars);
    }

    /** @return list<array<string, mixed>> */
    private function blockBars($blocks, string $from, string $to, array $index): array
    {
        $dates = array_keys($index);
        $bars = [];
        foreach ($blocks as $b) {
            if ($b->end_date <= $from || $b->start_date >= $to) {
                continue;
            }
            $start = $b->start_date < $from ? 0 : $index[$b->start_date];
            $lastNight = CarbonImmutable::parse($b->end_date)->subDay()->toDateString();
            $end = $lastNight >= $to ? count($dates) - 1 : $index[$lastNight];
            $bars[] = [
                'kind' => 'block',
                'start' => $start,
                'end' => $end,
                'status' => $b->block_type === 'owner_hold' ? 'blocked' : 'out_of_service',
                'block_type' => $b->block_type,
                'reason' => $b->reason,
                'check_in' => $b->start_date,
                'check_out' => $b->end_date,
                'cont_before' => $b->start_date < $from,
                'cont_after' => $b->end_date > $to,
            ];
        }

        return $bars;
    }

    /**
     * Status filter on the assembled rows (cheap: the window is already in memory).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function applyStatusFilter(array $rows, string $status): array
    {
        $keep = match ($status) {
            'inactive' => fn (array $rt) => ! $rt['is_active'] || collect($rt['products'])->contains(fn ($p) => ! $p['is_active']),
            'active' => fn (array $rt) => $rt['is_active'],
            'sold_out' => fn (array $rt) => collect($rt['inventory'])->contains(fn ($d) => $d['a'] === 0),
            'stop_sell' => fn (array $rt) => collect($rt['inventory'])->contains(fn ($d) => $d['ss'])
                || collect($rt['products'])->contains(fn ($p) => collect($p['days'])->contains(fn ($d) => $d['ss'])),
            'restricted' => fn (array $rt) => collect($rt['products'])->contains(fn ($p) => collect($p['days'])->contains(
                fn ($d) => $d['cta'] || $d['ctd'] || $d['min'] > 1 || $d['mla'] !== null)),
            default => null,
        };

        return $keep === null ? $rows : array_values(array_filter($rows, $keep));
    }
}
