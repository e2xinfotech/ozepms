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
        $property = Property::query()->findOrFail($changes->propertyId);
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
            return new AriApplyResult(0, 0, 0, $skipped, $this->journal->version($property->id));
        }

        $userId = $by?->id ?? auth()->id();
        $source = $userId !== null ? 'user' : 'system';
        $mask = $changes->weekdayMask();

        return Tx::run(function () use ($changes, $property, $from, $to, $roomTypes, $products, $skipped, $userId, $source, $mask) {
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

            $version = $this->journal->version($property->id);
            if ($inventoryRows + $ariRows + $occupancyRows > 0) {
                $version = $this->journal->bump($property->id);
                $this->audit->log('ari.updated', $property, ['after' => [
                    'from' => $from->toDateString(), 'to' => $to->toDateString(), 'weekdays' => $changes->weekdays,
                    'room_types' => $roomTypes->keys()->all(), 'products' => $products->keys()->all(),
                    'values' => $changes->payload(),
                ]], $property->id, $userId);
            }

            return new AriApplyResult($inventoryRows, $ariRows, $occupancyRows, $skipped, $version);
        });
    }

    /**
     * UPDATE … WHERE key = id AND stay_date in [from, to] (+ weekday filter). Returns rows whose
     * values actually changed (MySQL "affected rows").
     *
     * @param  list<string>  $sets
     * @param  list<mixed>  $bindings
     */
    private function updateRange(string $table, string $key, int $id, array $sets, array $bindings, CarbonImmutable $from, CarbonImmutable $to, AriChangeSet $changes): int
    {
        if ($sets === []) {
            return 0;
        }
        $sql = "UPDATE {$table} SET ".implode(', ', $sets).", updated_at = ? WHERE {$key} = ? AND stay_date >= ? AND stay_date <= ?";
        $params = [...$bindings, now(), $id, $from->toDateString(), $to->toDateString()];
        if ($changes->weekdays !== []) {
            // MySQL WEEKDAY(): Monday = 0 … Sunday = 6.
            $sql .= ' AND WEEKDAY(stay_date) IN ('.implode(',', array_fill(0, count($changes->weekdays), '?')).')';
            array_push($params, ...array_map(fn ($d) => $d - 1, $changes->weekdays));
        }

        return DB::update($sql, $params);
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
                $rows = array_map(fn ($d) => [
                    'product_id' => $productId, 'stay_date' => $d, 'adults' => $adults,
                    'property_id' => $propertyId, 'price' => $price, 'updated_at' => $now,
                ], $chunk);
                DB::table('ari_daily_occupancy')->upsert($rows, ['product_id', 'stay_date', 'adults'], ['price', 'updated_at']);
                $count += count($rows);
            }
        }

        return $count;
    }

    private function skip(string $type, int $id, ?string $field, string $reason, int $dates): array
    {
        return ['type' => $type, 'id' => $id, 'field' => $field, 'reason' => $reason, 'dates' => $dates];
    }
}
