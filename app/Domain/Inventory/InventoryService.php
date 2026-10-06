<?php

namespace App\Domain\Inventory;

use App\Domain\Accommodation\UnitNightGuard;
use App\Domain\Inventory\Exceptions\NotAvailableException;
use App\Infrastructure\Database\Tx;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Daily room-type inventory (inventory_daily): the single source of truth for how many rooms
 * can still be sold. Stay ranges are [from, to): $to is the check-out date (exclusive).
 *
 * Rooms left on a night = LEAST(total_units, COALESCE(sell_limit, total_units)) − ooo_units − sold − held.
 * The database CHECK ck_inv_no_overbook (sold + held + ooo_units ≤ total_units) is the last line
 * of defence; every write here is guarded so it never has to fire.
 */
class InventoryService
{
    /** MySQL error number for a violated CHECK constraint. */
    private const CHECK_VIOLATION = 3819;

    /** Block types that take a PMS room out of sale. */
    public const BLOCKING_TYPES = ['out_of_order', 'maintenance', 'owner_hold'];

    /** @var array<int, int> room type id => property id */
    private array $propertyOf = [];

    public function __construct(
        private readonly AriJournal $journal,
        private readonly UnitNightGuard $guard,
    ) {}

    /**
     * Takes $rooms rooms of the room type for every night of [from, to). Call inside the caller's
     * transaction. Uses one guarded UPDATE (architecture §9); when fewer rows than nights are
     * updated the change is rolled back. $hold = true increments `held` (booking-engine hold)
     * instead of `sold`. Several room types: use reserveMany() so locks are taken in a fixed order.
     *
     * @throws NotAvailableException with the dates that had no room left
     */
    public function reserve(int $roomTypeId, CarbonImmutable $from, CarbonImmutable $to, int $rooms = 1, bool $hold = false): void
    {
        $nights = $this->nights($from, $to, $rooms);
        $column = $hold ? 'held' : 'sold';

        Tx::run(function () use ($roomTypeId, $from, $to, $rooms, $nights, $column) {
            $affected = $this->guardedTake($roomTypeId, $from, $to, $rooms, $column);
            if ($affected !== $nights && $this->fillMissing($roomTypeId, $from, $to) > 0) {
                // Dates beyond the horizon had no rows yet; they exist now, so try those nights again.
                $affected = $this->guardedTake($roomTypeId, $from, $to, $rooms, $column, onlyUntouched: true) + $affected;
            }
            if ($affected !== $nights) {
                throw new NotAvailableException($roomTypeId, $this->fullDates($roomTypeId, $from, $to, $rooms));
            }
            $this->changed($roomTypeId, $from, $to, [$column => $rooms], 'reservation');
        }, 1);
    }

    /**
     * Reserves several room types at once, always locking in (room type, date) order so two
     * bookings never wait on each other's rows.
     *
     * @param  list<array{room_type_id: int, from: CarbonImmutable, to: CarbonImmutable, rooms?: int}>  $items
     */
    public function reserveMany(array $items, bool $hold = false): void
    {
        usort($items, fn ($a, $b) => [$a['room_type_id'], $a['from']->toDateString()] <=> [$b['room_type_id'], $b['from']->toDateString()]);
        Tx::run(function () use ($items, $hold) {
            foreach ($items as $item) {
                $this->reserve((int) $item['room_type_id'], $item['from'], $item['to'], (int) ($item['rooms'] ?? 1), $hold);
            }
        }, 1);
    }

    /** Gives back rooms taken by reserve() (cancellation, shortened stay, expired hold). Never goes below zero. */
    public function release(int $roomTypeId, CarbonImmutable $from, CarbonImmutable $to, int $rooms = 1, bool $hold = false): void
    {
        $this->nights($from, $to, $rooms);
        $column = $hold ? 'held' : 'sold';

        Tx::run(function () use ($roomTypeId, $from, $to, $rooms, $column) {
            DB::update(
                "UPDATE inventory_daily SET {$column} = {$column} - LEAST({$column}, ?), updated_at = ?
                  WHERE room_type_id = ? AND stay_date >= ? AND stay_date < ?",
                [$rooms, now(), $roomTypeId, $from->toDateString(), $to->toDateString()],
            );
            $this->changed($roomTypeId, $from, $to, [$column => -$rooms], 'reservation');
        }, 1);
    }

    /** Turns a hold into a sale for every night (booking paid): held − rooms, sold + rooms. */
    public function confirmHold(int $roomTypeId, CarbonImmutable $from, CarbonImmutable $to, int $rooms = 1): void
    {
        $nights = $this->nights($from, $to, $rooms);

        Tx::run(function () use ($roomTypeId, $from, $to, $rooms, $nights) {
            // held + sold stays the same, so the CHECK cannot fail; the guard only makes sure the hold exists.
            $affected = DB::update(
                'UPDATE inventory_daily SET held = held - ?, sold = sold + ?, updated_at = ?
                  WHERE room_type_id = ? AND stay_date >= ? AND stay_date < ? AND held >= ?',
                [$rooms, $rooms, now(), $roomTypeId, $from->toDateString(), $to->toDateString(), $rooms],
            );
            if ($affected !== $nights) {
                throw new NotAvailableException($roomTypeId, []);
            }
            $this->changed($roomTypeId, $from, $to, ['held' => -$rooms, 'sold' => $rooms], 'reservation');
        }, 1);
    }

    /**
     * Sets total_units = number of active PMS rooms of the room type, from the property's today on
     * (past nights keep their history), and recounts the rooms out of order. Refuses
     * (NotAvailableException) when the new total would be below the rooms already sold / held.
     */
    public function syncUnitCount(int $roomTypeId): void
    {
        $propertyId = $this->propertyId($roomTypeId);
        if ($propertyId === null) {
            return;
        }
        $from = $this->today($propertyId);
        $this->ensureRoomTypeRows($roomTypeId, $from, $this->horizonEnd($from));
        $last = DB::table('inventory_daily')->where('room_type_id', $roomTypeId)->max('stay_date');
        $to = CarbonImmutable::parse($last)->addDay();

        Tx::run(function () use ($roomTypeId, $propertyId, $from, $to) {
            $total = $this->activeUnitCount($roomTypeId);
            $this->writeUnits($roomTypeId, $from, $to, $total);
            $this->changed($roomTypeId, $from, $to, ['total_units' => $total], 'system');
        }, 1);
    }

    /**
     * Makes sure inventory_daily rows exist for every room type and ari_daily rows for every
     * product of the property from its today up to today + $days (default config
     * ozepms.inventory.horizon_days). Existing rows are never changed. Returns rows created.
     */
    public function ensureHorizon(Property $property, ?int $days = null): int
    {
        $from = CarbonImmutable::parse($this->guard->today($property));
        $to = $this->horizonEnd($from, $days);

        $created = $this->insertInventoryRows($property->id, $from, $to);
        $created += $this->insertAriRows($property->id, $from, $to);
        if ($created > 0) {
            $this->recountBlocksOfProperty($property->id, $from, $to);
            $this->journal->bump($property->id);
        }

        return $created;
    }

    /** Creates missing inventory rows of one room type for [from, to). Returns rows created. */
    public function ensureRoomTypeRows(int $roomTypeId, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $propertyId = $this->propertyId($roomTypeId);
        if ($propertyId === null || $to->lessThanOrEqualTo($from)) {
            return 0;
        }
        $created = $this->insertInventoryRows($propertyId, $from, $to, roomTypeId: $roomTypeId);
        if ($created > 0 && $this->hasBlocks($roomTypeId, $from, $to)) {
            $this->recountBlocked($roomTypeId, $from, $to);
        }

        return $created;
    }

    /**
     * Creates missing ari_daily rows of one product for [from, to) with the product's default
     * price and the rate plan's default restrictions. Fills an empty price of a manual product
     * with its default price (a product that was derived before). Returns rows created or filled.
     */
    public function ensureProductRows(int $productId, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $product = DB::table('room_type_rate_plans')->where('id', $productId)->first(['property_id', 'pricing_mode', 'default_price']);
        if ($product === null || $to->lessThanOrEqualTo($from)) {
            return 0;
        }
        $created = $this->insertAriRows((int) $product->property_id, $from, $to, productId: $productId);
        if ($product->pricing_mode === 'manual' && $product->default_price !== null) {
            $created += DB::table('ari_daily')
                ->where('product_id', $productId)
                ->whereBetween('stay_date', [$from->toDateString(), $to->subDay()->toDateString()])
                ->whereNull('price')
                ->update(['price' => $product->default_price, 'updated_at' => now()]);
        }

        return $created;
    }

    /** First stay date after the horizon of a property whose today is $from. */
    public function horizonEnd(CarbonImmutable $from, ?int $days = null): CarbonImmutable
    {
        return $from->addDays(max(1, $days ?? (int) config('ozepms.inventory.horizon_days', 730)));
    }

    /** The property's operational "today" (business date or local date). */
    public function today(int $propertyId): CarbonImmutable
    {
        $property = Property::query()->withTrashed()->findOrFail($propertyId);

        return CarbonImmutable::parse($this->guard->today($property));
    }

    /**
     * Takes $units rooms out of sale for [from, to) (out of order / out of service): ooo_units + units.
     * Guarded like reserve(); throws NotAvailableException when the rooms are already sold.
     */
    public function blockUnits(int $roomTypeId, CarbonImmutable $from, CarbonImmutable $to, int $units = 1): void
    {
        $nights = $this->nights($from, $to, $units);

        Tx::run(function () use ($roomTypeId, $from, $to, $units, $nights) {
            $this->fillMissing($roomTypeId, $from, $to);
            $affected = DB::update(
                'UPDATE inventory_daily SET ooo_units = ooo_units + ?, updated_at = ?
                  WHERE room_type_id = ? AND stay_date >= ? AND stay_date < ?
                    AND total_units >= ooo_units + sold + held + ?',
                [$units, now(), $roomTypeId, $from->toDateString(), $to->toDateString(), $units],
            );
            if ($affected !== $nights) {
                throw new NotAvailableException($roomTypeId, $this->fullDates($roomTypeId, $from, $to, $units, ignoreLimit: true));
            }
            $this->changed($roomTypeId, $from, $to, ['ooo_units' => $units], 'system');
        }, 1);
    }

    /** Puts $units rooms back on sale for [from, to): ooo_units − units (never below zero). */
    public function unblockUnits(int $roomTypeId, CarbonImmutable $from, CarbonImmutable $to, int $units = 1): void
    {
        $this->nights($from, $to, $units);

        Tx::run(function () use ($roomTypeId, $from, $to, $units) {
            DB::update(
                'UPDATE inventory_daily SET ooo_units = ooo_units - LEAST(ooo_units, ?), updated_at = ?
                  WHERE room_type_id = ? AND stay_date >= ? AND stay_date < ?',
                [$units, now(), $roomTypeId, $from->toDateString(), $to->toDateString()],
            );
            $this->changed($roomTypeId, $from, $to, ['ooo_units' => -$units], 'system');
        }, 1);
    }

    /**
     * Recounts ooo_units for [from, to) from the open unit_blocks of the room type's active rooms
     * (out_of_order, maintenance, owner_hold). Used by the UnitBlockChanged listener.
     *
     * @throws NotAvailableException when the blocked rooms are needed by sold / held rooms
     */
    public function recountBlocked(int $roomTypeId, CarbonImmutable $from, CarbonImmutable $to): void
    {
        if ($to->lessThanOrEqualTo($from) || $this->propertyId($roomTypeId) === null) {
            return;
        }
        Tx::run(function () use ($roomTypeId, $from, $to) {
            $this->writeUnits($roomTypeId, $from, $to, null);
            $this->changed($roomTypeId, $from, $to, ['ooo_units' => 'recount'], 'system');
        }, 1);
    }

    /** Rooms still sellable per night of [from, to), keyed by date; a missing night is absent. */
    public function remaining(int $roomTypeId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::table('inventory_daily')
            ->where('room_type_id', $roomTypeId)
            ->where('stay_date', '>=', $from->toDateString())
            ->where('stay_date', '<', $to->toDateString())
            ->orderBy('stay_date')
            ->selectRaw('stay_date, CAST(LEAST(total_units, COALESCE(sell_limit, total_units)) AS SIGNED) - ooo_units - sold - held AS rooms_left')
            ->pluck('rooms_left', 'stay_date')
            ->map(fn ($v) => max(0, (int) $v))
            ->all();
    }

    // ------------------------------------------------------------------------------------------

    private function nights(CarbonImmutable $from, CarbonImmutable $to, int $rooms): int
    {
        if ($rooms < 1) {
            throw new InvalidArgumentException('At least one room is required.');
        }
        $nights = (int) $from->startOfDay()->diffInDays($to->startOfDay(), false);
        if ($nights < 1) {
            throw new InvalidArgumentException('The stay must have at least one night.');
        }

        return $nights;
    }

    /** The guarded UPDATE of architecture §9. Additions only, so unsigned columns never underflow. */
    private function guardedTake(int $roomTypeId, CarbonImmutable $from, CarbonImmutable $to, int $rooms, string $column, bool $onlyUntouched = false): int
    {
        // $onlyUntouched: second pass after missing rows were created. Rows taken by the first pass
        // already carry the rooms (column > 0) and are skipped; the caller adds both passes.
        return DB::update(
            "UPDATE inventory_daily SET {$column} = {$column} + ?, updated_at = ?
              WHERE room_type_id = ? AND stay_date >= ? AND stay_date < ?
                AND LEAST(total_units, COALESCE(sell_limit, total_units)) >= ooo_units + sold + held + ?"
                .($onlyUntouched ? ' AND '.$column.' = 0' : ''),
            [$rooms, now(), $roomTypeId, $from->toDateString(), $to->toDateString(), $rooms],
        );
    }

    /** Creates inventory rows missing in [from, to); returns how many were created. */
    private function fillMissing(int $roomTypeId, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $have = DB::table('inventory_daily')->where('room_type_id', $roomTypeId)
            ->where('stay_date', '>=', $from->toDateString())->where('stay_date', '<', $to->toDateString())->count();
        if ($have >= (int) $from->diffInDays($to)) {
            return 0;
        }

        return $this->ensureRoomTypeRows($roomTypeId, $from, $to);
    }

    /** @return list<string> nights of [from, to) without $rooms rooms left (or without a row) */
    private function fullDates(int $roomTypeId, CarbonImmutable $from, CarbonImmutable $to, int $rooms, bool $ignoreLimit = false): array
    {
        $capacity = $ignoreLimit ? 'total_units' : 'LEAST(total_units, COALESCE(sell_limit, total_units))';
        $rows = DB::table('inventory_daily')->where('room_type_id', $roomTypeId)
            ->where('stay_date', '>=', $from->toDateString())->where('stay_date', '<', $to->toDateString())
            ->selectRaw("stay_date, {$capacity} >= ooo_units + sold + held + ? AS ok", [$rooms])
            ->pluck('ok', 'stay_date');
        $dates = [];
        for ($d = $from; $d->lessThan($to); $d = $d->addDay()) {
            $key = $d->toDateString();
            if (! (bool) ($rows[$key] ?? false)) {
                $dates[] = $key;
            }
        }

        return $dates;
    }

    private function activeUnitCount(int $roomTypeId): int
    {
        return DB::table('physical_units')->where('room_type_id', $roomTypeId)
            ->where('is_active', true)->whereNull('deleted_at')->count();
    }

    private function hasBlocks(int $roomTypeId, CarbonImmutable $from, CarbonImmutable $to): bool
    {
        return DB::table('unit_blocks as ub')
            ->join('physical_units as pu', 'pu.id', '=', 'ub.unit_id')
            ->where('pu.room_type_id', $roomTypeId)
            ->whereNull('ub.released_at')
            ->whereIn('ub.block_type', self::BLOCKING_TYPES)
            ->where('ub.start_date', '<', $to->toDateString())
            ->where('ub.end_date', '>', $from->toDateString())
            ->exists();
    }

    /**
     * Writes ooo_units (recounted from unit_blocks) and, when $total is given, total_units for
     * [from, to). Consecutive nights with the same count are written by one UPDATE, so a room
     * type with a few blocks needs a few statements whatever the range. Both values change in the
     * same statement, so the CHECK sees the final state of each row.
     */
    private function writeUnits(int $roomTypeId, CarbonImmutable $from, CarbonImmutable $to, ?int $total): void
    {
        $blocked = $this->blockedPerNight($roomTypeId, $from, $to);

        $segments = [];
        $start = $from;
        $current = $blocked[$from->toDateString()] ?? 0;
        for ($d = $from->addDay(); $d->lessThanOrEqualTo($to); $d = $d->addDay()) {
            $count = $d->equalTo($to) ? -1 : ($blocked[$d->toDateString()] ?? 0);
            if ($count !== $current) {
                $segments[] = [$start, $d, $current];
                $start = $d;
                $current = $count;
            }
        }

        foreach ($segments as [$segFrom, $segTo, $ooo]) {
            $sets = 'ooo_units = ?, updated_at = ?';
            $bindings = [$ooo, now()];
            if ($total !== null) {
                $sets = 'total_units = ?, sell_limit = IF(sell_limit IS NULL, NULL, LEAST(sell_limit, ?)), '.$sets;
                array_unshift($bindings, $total, $total);
            }
            try {
                DB::update(
                    "UPDATE inventory_daily SET {$sets} WHERE room_type_id = ? AND stay_date >= ? AND stay_date < ?",
                    [...$bindings, $roomTypeId, $segFrom->toDateString(), $segTo->toDateString()],
                );
            } catch (QueryException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) !== self::CHECK_VIOLATION) {
                    throw $e;
                }
                $capacity = $total ?? 'total_units';
                $row = DB::table('inventory_daily')->where('room_type_id', $roomTypeId)
                    ->where('stay_date', '>=', $segFrom->toDateString())->where('stay_date', '<', $segTo->toDateString())
                    ->whereRaw("sold + held + ? > {$capacity}", [$ooo])
                    ->orderBy('stay_date')->first(['stay_date', 'sold', 'held']);
                $date = $row?->stay_date ?? $segFrom->toDateString();
                throw new NotAvailableException($roomTypeId, [$date], $total !== null
                    ? __('inventory.errors.unit_count', ['date' => $date, 'booked' => (int) ($row?->sold ?? 0) + (int) ($row?->held ?? 0) + $ooo])
                    : __('inventory.errors.rooms_needed', ['date' => $date]));
            }
        }
    }

    /** @return array<string, int> date => blocked active rooms, only dates with at least one */
    private function blockedPerNight(int $roomTypeId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $blocks = DB::table('unit_blocks as ub')
            ->join('physical_units as pu', 'pu.id', '=', 'ub.unit_id')
            ->where('pu.room_type_id', $roomTypeId)
            ->where('pu.is_active', true)
            ->whereNull('pu.deleted_at')
            ->whereNull('ub.released_at')
            ->whereIn('ub.block_type', self::BLOCKING_TYPES)
            ->where('ub.start_date', '<', $to->toDateString())
            ->where('ub.end_date', '>', $from->toDateString())
            ->get(['ub.unit_id', 'ub.start_date', 'ub.end_date']);

        // A room counts once per night even if two of its blocks overlap.
        $perNight = [];
        foreach ($blocks as $block) {
            $start = CarbonImmutable::parse($block->start_date)->max($from);
            $end = CarbonImmutable::parse($block->end_date)->min($to);
            for ($d = $start; $d->lessThan($end); $d = $d->addDay()) {
                $perNight[$d->toDateString()][$block->unit_id] = true;
            }
        }

        return array_map('count', $perNight);
    }

    private function recountBlocksOfProperty(int $propertyId, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $roomTypeIds = DB::table('unit_blocks as ub')
            ->join('physical_units as pu', 'pu.id', '=', 'ub.unit_id')
            ->where('ub.property_id', $propertyId)
            ->whereNull('ub.released_at')
            ->where('ub.end_date', '>', $from->toDateString())
            ->where('ub.start_date', '<', $to->toDateString())
            ->distinct()->pluck('pu.room_type_id');
        foreach ($roomTypeIds as $roomTypeId) {
            $this->writeUnits((int) $roomTypeId, $from, $to, null);
        }
    }

    /** INSERT IGNORE … SELECT over a generated date list: one statement per call. */
    private function insertInventoryRows(int $propertyId, CarbonImmutable $from, CarbonImmutable $to, ?int $roomTypeId = null): int
    {
        if ($to->lessThanOrEqualTo($from)) {
            return 0;
        }
        $created = 0;
        // The recursive date list is limited by cte_max_recursion_depth (1000), so long ranges go in parts.
        foreach ($this->chunks($from, $to) as [$a, $b]) {
            $sql = <<<'SQL'
INSERT IGNORE INTO inventory_daily (room_type_id, stay_date, property_id, total_units, ooo_units, sold, held, sell_limit, stop_sell, updated_at)
WITH RECURSIVE d (dt) AS (SELECT CAST(? AS DATE) UNION ALL SELECT dt + INTERVAL 1 DAY FROM d WHERE dt < ?)
SELECT rt.id, d.dt, rt.property_id, COALESCE(u.units, 0), 0, 0, 0, NULL, 0, ?
  FROM room_types rt
  LEFT JOIN (SELECT room_type_id, COUNT(*) AS units FROM physical_units
              WHERE property_id = ? AND is_active = 1 AND deleted_at IS NULL GROUP BY room_type_id) u ON u.room_type_id = rt.id
  CROSS JOIN d
 WHERE rt.property_id = ? AND rt.deleted_at IS NULL
SQL;
            $bindings = [$a->toDateString(), $b->subDay()->toDateString(), now(), $propertyId, $propertyId];
            if ($roomTypeId !== null) {
                $sql .= ' AND rt.id = ?';
                $bindings[] = $roomTypeId;
            }
            $created += DB::affectingStatement($sql, $bindings);
        }

        return $created;
    }

    private function insertAriRows(int $propertyId, CarbonImmutable $from, CarbonImmutable $to, ?int $productId = null): int
    {
        if ($to->lessThanOrEqualTo($from)) {
            return 0;
        }
        $created = 0;
        foreach ($this->chunks($from, $to) as [$a, $b]) {
            $sql = <<<'SQL'
INSERT IGNORE INTO ari_daily (product_id, stay_date, property_id, price, min_los, max_los, min_los_arrival, cta, ctd, stop_sell, cutoff_days, max_advance_days, updated_at)
WITH RECURSIVE d (dt) AS (SELECT CAST(? AS DATE) UNION ALL SELECT dt + INTERVAL 1 DAY FROM d WHERE dt < ?)
SELECT p.id, d.dt, p.property_id,
       IF(p.pricing_mode = 'manual', p.default_price, NULL),
       IF(rp.default_min_los > 1, rp.default_min_los, NULL),
       NULLIF(rp.default_max_los, 0),
       NULL, 0, 0, 0,
       NULLIF(rp.min_advance_days, 0),
       NULLIF(rp.max_advance_days, 0),
       ?
  FROM room_type_rate_plans p
  JOIN rate_plans rp ON rp.id = p.rate_plan_id
  JOIN room_types rt ON rt.id = p.room_type_id
  CROSS JOIN d
 WHERE p.property_id = ? AND rp.deleted_at IS NULL AND rt.deleted_at IS NULL
SQL;
            $bindings = [$a->toDateString(), $b->subDay()->toDateString(), now(), $propertyId];
            if ($productId !== null) {
                $sql .= ' AND p.id = ?';
                $bindings[] = $productId;
            }
            $created += DB::affectingStatement($sql, $bindings);
        }

        return $created;
    }

    /** @return list<array{CarbonImmutable, CarbonImmutable}> [from, to) parts of at most 900 days */
    private function chunks(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $parts = [];
        for ($a = $from; $a->lessThan($to); $a = $a->addDays(900)) {
            $parts[] = [$a, $a->addDays(900)->min($to)];
        }

        return $parts;
    }

    private function propertyId(int $roomTypeId): ?int
    {
        if (! array_key_exists($roomTypeId, $this->propertyOf)) {
            $id = DB::table('room_types')->where('id', $roomTypeId)->value('property_id');
            if ($id === null) {
                return null;
            }
            $this->propertyOf[$roomTypeId] = (int) $id;
        }

        return $this->propertyOf[$roomTypeId];
    }

    /** Change log row + ari_version bump for an inventory change of [from, to). */
    private function changed(int $roomTypeId, CarbonImmutable $from, CarbonImmutable $to, array $payload, string $source): void
    {
        $propertyId = $this->propertyId($roomTypeId);
        if ($propertyId === null) {
            return;
        }
        $this->journal->record($propertyId, 'inventory', $roomTypeId, null, $from, $to->subDay(), $payload, $source, auth()->id());
        $this->journal->bump($propertyId);
    }
}
