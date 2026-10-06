<?php

namespace App\Domain\Inventory;

use App\Domain\Audit\AuditLogger;
use App\Infrastructure\Database\Tx;
use App\Models\Property;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Writes availability, rates and restrictions (single date, range or bulk edit).
 * Every successful change increments properties.ari_version and writes ari_change_log rows.
 */
class AriService
{
    /** Edits may reach this many days past the horizon (rows are created on demand). */
    private const EXTRA_DAYS = 366;

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly AriJournal $journal,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Applies the change set in one transaction.
     *
     * - Room-type fields (stopSell, sellLimit) go to inventory_daily for change->roomTypeIds.
     * - Product fields go to ari_daily / ari_daily_occupancy for change->productIds.
     * - Derived products: price / occupancy price edits are skipped (reason derived_price);
     *   restriction edits are skipped when the product inherits restrictions
     *   (reason inherits_restrictions), except stop sell, which every product may set for itself.
     * - Ids of another property are skipped (reason not_found); past dates are skipped (reason past).
     */
    public function apply(AriChangeSet $changes, ?User $by = null): AriApplyResult
    {
        return $this->applyMany([$changes], $by);
    }

    /**
     * Applies several change sets of one property in one transaction (used by "copy values", where
     * every run of dates gets its own values): one ari_version increment and one audit record for
     * the whole operation; ari_change_log keeps one row per change set and scope as usual.
     *
     * @param  list<AriChangeSet>  $sets
     * @param  array<string, mixed>  $auditContext  extra details for the audit record (e.g. the copy source)
     */
    public function applyMany(array $sets, ?User $by = null, string $auditAction = 'ari.updated', array $auditContext = []): AriApplyResult
    {
        if ($sets === []) {
            throw new \InvalidArgumentException('No change sets given.');
        }
        $propertyId = $sets[0]->propertyId;
        foreach ($sets as $set) {
            if ($set->propertyId !== $propertyId) {
                throw new \InvalidArgumentException('All change sets must belong to one property.');
            }
        }
        $property = Property::query()->findOrFail($propertyId);
        $userId = $by?->id ?? auth()->id();
        $source = $userId !== null ? 'user' : 'system';

        return Tx::run(function () use ($sets, $property, $userId, $source, $auditAction, $auditContext) {
            $totals = [0, 0, 0];
            $skipped = [];
            $touched = ['room_types' => [], 'products' => [], 'from' => null, 'to' => null];
            foreach ($sets as $set) {
                [$inv, $ari, $occ, $skip, $info] = $this->applyOne($set, $property, $userId, $source);
                $totals[0] += $inv;
                $totals[1] += $ari;
                $totals[2] += $occ;
                array_push($skipped, ...$skip);
                if ($info !== null && $inv + $ari + $occ > 0) {
                    $touched['room_types'] = array_merge($touched['room_types'], $info['room_types']);
                    $touched['products'] = array_merge($touched['products'], $info['products']);
                    $touched['from'] = $touched['from'] === null ? $info['from'] : min($touched['from'], $info['from']);
                    $touched['to'] = $touched['to'] === null ? $info['to'] : max($touched['to'], $info['to']);
                }
            }

            $version = $this->journal->version($property->id);
            if (array_sum($totals) > 0) {
                $version = $this->journal->bump($property->id);
                $after = count($sets) === 1
                    ? ['from' => $touched['from'], 'to' => $touched['to'], 'weekdays' => $sets[0]->weekdays,
                        'room_types' => array_values(array_unique($touched['room_types'])), 'products' => array_values(array_unique($touched['products'])),
                        'values' => $sets[0]->payload()]
                    : ['from' => $touched['from'], 'to' => $touched['to'], 'changes' => count($sets),
                        'room_types' => array_values(array_unique($touched['room_types'])), 'products' => array_values(array_unique($touched['products']))];
                $this->audit->log($auditAction, $property, ['after' => $after + $auditContext], $property->id, $userId);
            }

            return new AriApplyResult($totals[0], $totals[1], $totals[2], $skipped, $version);
        });
    }

    /**
     * One change set (inside the caller's transaction, without version bump or audit).
     *
     * @return array{0: int, 1: int, 2: int, 3: list<array<string, mixed>>, 4: ?array<string, mixed>}
     */
    private function applyOne(AriChangeSet $changes, Property $property, ?int $userId, string $source): array
    {
        $today = $this->inventory->today($property->id);
        $limit = $this->inventory->horizonEnd($today)->addDays(self::EXTRA_DAYS - 1);
        $skipped = [];

        // Only today and later can change; dates far beyond the horizon are refused.
        $dates = $changes->dates();
        $past = count(array_filter($dates, fn ($d) => $d < $today->toDateString()));
        $beyond = count(array_filter($dates, fn ($d) => $d > $limit->toDateString()));
        $from = $changes->dateFrom->max($today);
        $to = $changes->dateTo->min($limit);

        $roomTypes = $changes->roomTypeIds === [] ? collect() : DB::table('room_types')
            ->where('property_id', $property->id)->whereIn('id', $changes->roomTypeIds)->whereNull('deleted_at')
            ->get(['id'])->keyBy('id');
        $products = $changes->productIds === [] ? collect() : DB::table('room_type_rate_plans as p')
            ->join('room_types as rt', 'rt.id', '=', 'p.room_type_id')
            ->where('p.property_id', $property->id)->whereIn('p.id', $changes->productIds)->whereNull('rt.deleted_at')
            ->get(['p.id', 'p.room_type_id', 'p.pricing_mode', 'p.inherit_restrictions', 'rt.max_adults'])->keyBy('id');

        foreach ($changes->roomTypeIds as $id) {
            if (! $roomTypes->has($id)) {
                $skipped[] = $this->skip('room_type', $id, null, 'not_found', 0);
            }
        }
        foreach ($changes->productIds as $id) {
            if (! $products->has($id)) {
                $skipped[] = $this->skip('product', $id, null, 'not_found', 0);
            }
        }
        foreach ([['past', $past], ['beyond_horizon', $beyond]] as [$reason, $count]) {
            if ($count > 0) {
                foreach ($roomTypes->keys() as $id) {
                    $skipped[] = $this->skip('room_type', (int) $id, null, $reason, $count);
                }
                foreach ($products->keys() as $id) {
                    $skipped[] = $this->skip('product', (int) $id, null, $reason, $count);
                }
            }
        }

        if ($to->lessThan($from) || ($roomTypes->isEmpty() && $products->isEmpty())) {
            return [0, 0, 0, $skipped, null];
        }

        $mask = $changes->weekdayMask();

        $inventoryRows = 0;
        $ariRows = 0;
        $occupancyRows = 0;
        $dayCount = count(array_filter($changes->dates(), fn ($d) => $d >= $from->toDateString() && $d <= $to->toDateString()));

        // Room-type level -------------------------------------------------------------------
        if ($changes->hasRoomTypeChanges()) {
            foreach ($roomTypes->keys() as $roomTypeId) {
                $roomTypeId = (int) $roomTypeId;
                $this->inventory->ensureRoomTypeRows($roomTypeId, $from, $to->addDay());
                $sets = [];
                $bindings = [];
                if ($changes->stopSell !== null) {
                    $sets[] = 'stop_sell = ?';
                    $bindings[] = $changes->stopSell ? 1 : 0;
                }
                if ($changes->sellLimit !== null) {
                    if ($changes->sellLimit === false) {
                        $sets[] = 'sell_limit = NULL';
                    } else {
                        $capped = $this->dateFilter(DB::table('inventory_daily')->where('room_type_id', $roomTypeId), $from, $to, $changes)
                            ->where('total_units', '<', $changes->sellLimit)->count();
                        if ($capped > 0) {
                            $skipped[] = $this->skip('room_type', $roomTypeId, 'sell_limit', 'sell_limit_too_high', $capped);
                        }
                        $sets[] = 'sell_limit = LEAST(?, total_units)';
                        $bindings[] = $changes->sellLimit;
                    }
                }
                $changed = $this->updateRange('inventory_daily', 'room_type_id', $roomTypeId, $sets, $bindings, $from, $to, $changes);
                $inventoryRows += $changed;
                if ($changed > 0) {
                    $this->journal->record($property->id, 'inventory', $roomTypeId, null, $from, $to,
                        array_intersect_key($changes->payload(), array_flip(['stop_sell', 'sell_limit'])), $source, $userId, $mask);
                }
            }
        }

        // Product level ---------------------------------------------------------------------
        foreach ($products as $product) {
            $productId = (int) $product->id;
            $derived = $product->pricing_mode === 'derived';
            $inherits = $derived && (bool) $product->inherit_restrictions;
            if (! $changes->hasProductChanges()) {
                continue;
            }
            $this->inventory->ensureProductRows($productId, $from, $to->addDay());

            $sets = [];
            $bindings = [];
            $rateFields = [];
            $restrictionFields = [];

            if ($changes->hasPriceChanges() && $derived) {
                $skipped[] = $this->skip('product', $productId, 'price', 'derived_price', $dayCount);
            }
            if ($changes->price !== null && ! $derived) {
                $sets[] = 'price = ?';
                $bindings[] = $changes->price;
                $rateFields['price'] = $changes->price;
            }

            $restrictions = [
                'min_los' => $changes->minLos === null ? null : ($changes->minLos > 0 ? $changes->minLos : 'NULL'),
                'max_los' => $changes->maxLos === null ? null : ($changes->maxLos > 0 ? $changes->maxLos : 'NULL'),
                'cta' => $changes->cta === null ? null : (int) $changes->cta,
                'ctd' => $changes->ctd === null ? null : (int) $changes->ctd,
                'cutoff_days' => $changes->minAdvance === null ? null : ($changes->minAdvance > 0 ? $changes->minAdvance : 'NULL'),
                'max_advance_days' => $changes->maxAdvance === null ? null : ($changes->maxAdvance > 0 ? $changes->maxAdvance : 'NULL'),
            ];
            $inheritedEdits = array_filter($restrictions, fn ($v) => $v !== null);
            if ($inherits && $inheritedEdits !== []) {
                $skipped[] = $this->skip('product', $productId, implode(',', array_keys($inheritedEdits)), 'inherits_restrictions', $dayCount);
            } else {
                foreach ($inheritedEdits as $column => $value) {
                    if ($value === 'NULL') {
                        $sets[] = "{$column} = NULL";
                        $restrictionFields[$column] = null;
                    } else {
                        $sets[] = "{$column} = ?";
                        $bindings[] = $value;
                        $restrictionFields[$column] = $value;
                    }
                }
            }
            if (($stop = $changes->productStopSell()) !== null) {
                $sets[] = 'stop_sell = ?';
                $bindings[] = $stop ? 1 : 0;
                $restrictionFields['stop_sell'] = $stop;
            }

            $changed = $this->updateRange('ari_daily', 'product_id', $productId, $sets, $bindings, $from, $to, $changes);

            $occChanged = 0;
            if ($changes->occupancyPrices !== null && ! $derived) {
                $occChanged = $this->writeOccupancy($property->id, $productId, (int) $product->max_adults, $changes, $from, $to, $skipped);
                if ($occChanged > 0) {
                    $rateFields['occupancy_prices'] = $changes->occupancyPrices;
                }
            }
            $ariRows += $changed;
            $occupancyRows += $occChanged;

            if ($rateFields !== [] && ($changed > 0 || $occChanged > 0)) {
                $this->journal->record($property->id, 'rate', (int) $product->room_type_id, $productId, $from, $to, $rateFields, $source, $userId, $mask);
            }
            if ($restrictionFields !== [] && $changed > 0) {
                $this->journal->record($property->id, 'restriction', (int) $product->room_type_id, $productId, $from, $to, $restrictionFields, $source, $userId, $mask);
            }
        }

        return [$inventoryRows, $ariRows, $occupancyRows, $skipped, [
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'room_types' => $roomTypes->keys()->map(fn ($id) => (int) $id)->all(), 'products' => $products->keys()->map(fn ($id) => (int) $id)->all(),
        ]];
    }

    /**
     * UPDATE … WHERE key = id AND stay_date in [from, to] (+ weekday filter), touching only rows
     * where at least one value really changes. Returns the number of rows changed.
     *
     * @param  list<string>  $sets  "column = expression" with ? placeholders
     * @param  list<mixed>  $bindings  values for the placeholders, in order
     */
    private function updateRange(string $table, string $key, int $id, array $sets, array $bindings, CarbonImmutable $from, CarbonImmutable $to, AriChangeSet $changes): int
    {
        if ($sets === []) {
            return 0;
        }
        // Same expressions again, compared NULL-safe with the current values.
        $differs = [];
        $differBindings = [];
        $offset = 0;
        foreach ($sets as $set) {
            [$column, $expression] = explode(' = ', $set, 2);
            $count = substr_count($expression, '?');
            $differs[] = "NOT ({$column} <=> {$expression})";
            array_push($differBindings, ...array_slice($bindings, $offset, $count));
            $offset += $count;
        }

        $sql = "UPDATE {$table} SET ".implode(', ', $sets).", updated_at = ? WHERE {$key} = ? AND stay_date >= ? AND stay_date <= ?";
        $params = [...$bindings, now(), $id, $from->toDateString(), $to->toDateString()];
        if ($changes->weekdays !== []) {
            // MySQL WEEKDAY(): Monday = 0 … Sunday = 6.
            $sql .= ' AND WEEKDAY(stay_date) IN ('.implode(',', array_fill(0, count($changes->weekdays), '?')).')';
            array_push($params, ...array_map(fn ($d) => $d - 1, $changes->weekdays));
        }
        $sql .= ' AND ('.implode(' OR ', $differs).')';

        return DB::update($sql, [...$params, ...$differBindings]);
    }

    private function dateFilter($query, CarbonImmutable $from, CarbonImmutable $to, AriChangeSet $changes)
    {
        $query->where('stay_date', '>=', $from->toDateString())->where('stay_date', '<=', $to->toDateString());
        if ($changes->weekdays !== []) {
            $query->whereIn(DB::raw('WEEKDAY(stay_date)'), array_map(fn ($d) => $d - 1, $changes->weekdays));
        }

        return $query;
    }

    /** Writes / removes fixed prices per number of adults. Returns rows written or removed. */
    private function writeOccupancy(int $propertyId, int $productId, int $maxAdults, AriChangeSet $changes, CarbonImmutable $from, CarbonImmutable $to, array &$skipped): int
    {
        $dates = array_values(array_filter($changes->dates(), fn ($d) => $d >= $from->toDateString() && $d <= $to->toDateString()));
        $count = 0;
        $now = now();
        foreach ($changes->occupancyPrices as $adults => $price) {
            if ($adults > max(1, $maxAdults)) {
                $skipped[] = $this->skip('product', $productId, 'occupancy_prices.'.$adults, 'occupancy_too_high', count($dates));

                continue;
            }
            if ($price === null) {
                $count += $this->dateFilter(DB::table('ari_daily_occupancy')->where('product_id', $productId)->where('adults', $adults), $from, $to, $changes)->delete();

                continue;
            }
            foreach (array_chunk($dates, 500) as $chunk) {
                $values = [];
                $bindings = [];
                foreach ($chunk as $d) {
                    $values[] = '(?, ?, ?, ?, ?, ?)';
                    array_push($bindings, $productId, $d, $adults, $propertyId, $price, $now);
                }
                // updated_at is set before price so it only moves when the price really changes;
                // MySQL then reports 1 per insert, 2 per changed row and 0 per unchanged row.
                $affected = DB::affectingStatement(
                    'INSERT INTO ari_daily_occupancy (product_id, stay_date, adults, property_id, price, updated_at) VALUES '
                    .implode(', ', $values)
                    .' ON DUPLICATE KEY UPDATE updated_at = IF(price <=> VALUES(price), updated_at, VALUES(updated_at)), price = VALUES(price)',
                    $bindings,
                );
                $count += $affected;
            }
        }

        return $count;
    }

    private function skip(string $type, int $id, ?string $field, string $reason, int $dates): array
    {
        return ['type' => $type, 'id' => $id, 'field' => $field, 'reason' => $reason, 'dates' => $dates];
    }
}
