<?php

namespace App\Domain\Inventory;

use App\Domain\Rates\DerivedPrice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Copy values": builds the AriChangeSets that copy rates and/or restrictions from a source date
 * range (of the same or another product) onto a target date range. AriService::applyMany() then
 * writes them in one transaction, so every rule of a normal calendar edit still applies (past
 * dates, derived prices, inherited restrictions, horizon, change log, ari_version).
 *
 * Date mapping (target night → source night)
 *   plain            the source range repeats: target night k takes source night k mod (source length)
 *   align_weekdays   a target night takes a source night of the same weekday; when the source has
 *                    several of that weekday they are used in turn, week after week. Target nights
 *                    whose weekday does not occur in the source are left alone.
 *
 * What is copied
 *   rates            price for the base occupancy (derived source products: their computed price)
 *                    and the per-adult prices (prices the source does not have are removed)
 *   restrictions     min / max stay, CTA, CTD, cutoff (min days ahead), max days ahead, closed
 *                    (derived source products that follow their parent: the parent's restrictions)
 * Source nights without a stored row (outside the horizon, archived) are skipped (reason no_source).
 *
 * Nights with identical values become one change set: a whole range with a weekday filter when the
 * nights form a weekly pattern, otherwise one change set per run of consecutive nights.
 */
final class AriCopyBuilder
{
    public const MAX_SOURCE_DAYS = 366;

    public const MAX_TARGET_DAYS = 366;

    public const MAX_CHANGE_SETS = 5000;

    /** @var list<array{type: string, id: int, field: ?string, reason: string, dates: int}> */
    private array $skipped = [];

    /**
     * @param  list<array{0: int, 1: int}>  $pairs  [source product id, target product id] (internal ids, same property)
     * @return array{sets: list<AriChangeSet>, skipped: list<array<string, mixed>>, target_dates: int, mapped_dates: int}
     */
    public function build(
        int $propertyId,
        string $sourceFrom,
        string $sourceTo,
        string $targetFrom,
        string $targetTo,
        array $pairs,
        bool $rates,
        bool $restrictions,
        bool $alignWeekdays = false,
    ): array {
        $this->skipped = [];
        [$sFrom, $sTo, $tFrom, $tTo] = $this->validate($sourceFrom, $sourceTo, $targetFrom, $targetTo, $rates, $restrictions, $pairs);

        $sourceDates = $this->dates($sFrom, $sTo);
        $targetDates = $this->dates($tFrom, $tTo);
        $map = $this->mapDates($sourceDates, $targetDates, $alignWeekdays);
        $unmapped = count($targetDates) - count($map);

        $sourceIds = array_values(array_unique(array_map(fn ($p) => (int) $p[0], $pairs)));
        $targetIds = array_values(array_unique(array_map(fn ($p) => (int) $p[1], $pairs)));
        $products = $this->products($propertyId, array_merge($sourceIds, $targetIds));
        $values = $this->sourceValues($propertyId, $products, $sourceIds, $sFrom, $sTo, $rates, $restrictions);

        $sets = [];
        foreach ($pairs as [$sourceId, $targetId]) {
            $sourceId = (int) $sourceId;
            $targetId = (int) $targetId;
            if (! isset($products[$sourceId], $products[$targetId])) {
                $this->skipped[] = ['type' => 'product', 'id' => $targetId, 'field' => null, 'reason' => 'not_found', 'dates' => 0];

                continue;
            }
            if ($unmapped > 0) {
                $this->skipped[] = ['type' => 'product', 'id' => $targetId, 'field' => null, 'reason' => 'no_source_weekday', 'dates' => $unmapped];
            }
            $maxAdults = max(1, (int) $products[$targetId]->max_adults);

            // Target nights grouped by identical values.
            $groups = [];
            $missing = 0;
            foreach ($map as $target => $source) {
                $v = $values[$sourceId][$source] ?? null;
                if ($v === null) {
                    $missing++;

                    continue;
                }
                $data = [];
                if ($rates && $v['price'] !== null) {
                    $data['price'] = $v['price'];
                    $occupancy = [];
                    for ($a = 1; $a <= $maxAdults; $a++) {
                        $occupancy[$a] = $v['occupancy'][$a] ?? null;
                    }
                    $data['occupancy_prices'] = $occupancy;
                }
                if ($restrictions && empty($v['no_restrictions'])) {
                    $data += [
                        'min_los' => $v['min_los'] ?? 0, 'max_los' => $v['max_los'] ?? 0,
                        'cta' => $v['cta'], 'ctd' => $v['ctd'],
                        'min_advance' => $v['cutoff'] ?? 0, 'max_advance' => $v['max_advance'] ?? 0,
                        'closed' => $v['closed'],
                    ];
                }
                if ($data === []) {
                    $missing++;

                    continue;
                }
                $groups[json_encode($data)][] = $target;
            }
            if ($missing > 0) {
                $this->skipped[] = ['type' => 'product', 'id' => $targetId, 'field' => null, 'reason' => 'no_source', 'dates' => $missing];
            }

            foreach ($groups as $key => $dates) {
                $data = json_decode($key, true);
                foreach ($this->segments($dates) as [$from, $to, $weekdays]) {
                    $sets[] = AriChangeSet::fromArray($propertyId, $data + [
                        'date_from' => $from, 'date_to' => $to, 'weekdays' => $weekdays, 'product_ids' => [$targetId],
                    ]);
                    if (count($sets) > self::MAX_CHANGE_SETS) {
                        throw ValidationException::withMessages(['target_to' => __('inventory.errors.copy_too_varied')]);
                    }
                }
            }
        }

        return ['sets' => $sets, 'skipped' => $this->skipped, 'target_dates' => count($targetDates), 'mapped_dates' => count($map)];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: CarbonImmutable, 3: CarbonImmutable} */
    private function validate(string $sourceFrom, string $sourceTo, string $targetFrom, string $targetTo, bool $rates, bool $restrictions, array $pairs): array
    {
        $errors = [];
        $parse = function (string $value, string $field) use (&$errors): ?CarbonImmutable {
            $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? CarbonImmutable::createFromFormat('!Y-m-d', $value) : false;
            if (! $date || $date->toDateString() !== $value) {
                $errors[$field] = __('inventory.errors.dates');

                return null;
            }

            return $date;
        };
        $sFrom = $parse($sourceFrom, 'source_from');
        $sTo = $parse($sourceTo, 'source_to');
        $tFrom = $parse($targetFrom, 'target_from');
        $tTo = $parse($targetTo, 'target_to');

        if ($sFrom && $sTo) {
            if ($sTo->lessThan($sFrom)) {
                $errors['source_to'] = __('inventory.errors.date_order');
            } elseif ($sFrom->diffInDays($sTo) + 1 > self::MAX_SOURCE_DAYS) {
                $errors['source_to'] = __('inventory.errors.range_too_long', ['max' => self::MAX_SOURCE_DAYS]);
            }
        }
        if ($tFrom && $tTo) {
            if ($tTo->lessThan($tFrom)) {
                $errors['target_to'] = __('inventory.errors.date_order');
            } elseif ($tFrom->diffInDays($tTo) + 1 > self::MAX_TARGET_DAYS) {
                $errors['target_to'] = __('inventory.errors.range_too_long', ['max' => self::MAX_TARGET_DAYS]);
            }
        }
        if (! $rates && ! $restrictions) {
            $errors['copy'] = __('inventory.errors.copy_nothing');
        }
        if ($pairs === []) {
            $errors['targets'] = __('inventory.errors.no_target');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [$sFrom, $sTo, $tFrom, $tTo];
    }

    /** @return list<string> */
    private function dates(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = [];
        for ($d = $from; $d->lessThanOrEqualTo($to); $d = $d->addDay()) {
            $out[] = $d->toDateString();
        }

        return $out;
    }

    /**
     * @param  list<string>  $source
     * @param  list<string>  $target
     * @return array<string, string> target date => source date
     */
    public function mapDates(array $source, array $target, bool $alignWeekdays): array
    {
        $map = [];
        if (! $alignWeekdays) {
            foreach ($target as $k => $date) {
                $map[$date] = $source[$k % count($source)];
            }

            return $map;
        }
        $byWeekday = [];
        foreach ($source as $date) {
            $byWeekday[(int) date('N', strtotime($date))][] = $date;
        }
        $seen = [];
        foreach ($target as $date) {
            $wd = (int) date('N', strtotime($date));
            if (! isset($byWeekday[$wd])) {
                continue;
            }
            $n = $seen[$wd] = ($seen[$wd] ?? -1) + 1;
            $map[$date] = $byWeekday[$wd][$n % count($byWeekday[$wd])];
        }

        return $map;
    }

    /**
     * Splits a sorted list of dates into change-set ranges. When the dates are exactly every night of
     * their range that falls on their weekdays (e.g. every Friday and Saturday), one range with a
     * weekday filter covers them; otherwise one range per run of consecutive nights.
     *
     * @param  list<string>  $dates
     * @return list<array{0: string, 1: string, 2: list<int>}>
     */
    public function segments(array $dates): array
    {
        sort($dates);
        $weekdays = array_values(array_unique(array_map(fn ($d) => (int) date('N', strtotime($d)), $dates)));
        sort($weekdays);
        $first = CarbonImmutable::createFromFormat('!Y-m-d', $dates[0]);
        $last = CarbonImmutable::createFromFormat('!Y-m-d', end($dates));
        $expected = 0;
        for ($d = $first; $d->lessThanOrEqualTo($last); $d = $d->addDay()) {
            if (in_array($d->dayOfWeekIso, $weekdays, true)) {
                $expected++;
            }
        }
        if ($expected === count($dates)) {
            $consecutive = (int) $first->diffInDays($last) + 1 === count($dates);

            return [[$dates[0], end($dates), $consecutive || count($weekdays) === 7 ? [] : $weekdays]];
        }

        $out = [];
        $start = $prev = $dates[0];
        foreach (array_slice($dates, 1) as $date) {
            if ($date !== date('Y-m-d', strtotime($prev.' +1 day'))) {
                $out[] = [$start, $prev, []];
                $start = $date;
            }
            $prev = $date;
        }
        $out[] = [$start, $prev, []];

        return $out;
    }

    /** @return array<int, object> products of the property with their parents (for derived prices), keyed by id */
    private function products(int $propertyId, array $ids): array
    {
        $out = [];
        $pending = $ids;
        for ($depth = 0; $pending !== [] && $depth < 6; $depth++) {
            $rows = DB::table('room_type_rate_plans as p')
                ->join('room_types as rt', 'rt.id', '=', 'p.room_type_id')
                ->join('rate_plans as rp', 'rp.id', '=', 'p.rate_plan_id')
                ->where('p.property_id', $propertyId)->whereIn('p.id', $pending)->whereNull('rt.deleted_at')
                ->get(['p.id', 'p.room_type_id', 'p.pricing_mode', 'p.parent_product_id', 'p.adjust_type', 'p.adjust_value',
                    'p.inherit_restrictions', 'rt.base_adults', 'rt.max_adults']);
            $pending = [];
            foreach ($rows as $row) {
                $out[(int) $row->id] = $row;
                if ($row->pricing_mode === 'derived' && $row->parent_product_id && ! isset($out[(int) $row->parent_product_id])) {
                    $pending[] = (int) $row->parent_product_id;
                }
            }
        }

        return $out;
    }

    /**
     * Effective values of each source product per source night.
     *
     * @param  array<int, object>  $products
     * @param  list<int>  $sourceIds
     * @return array<int, array<string, array<string, mixed>>> product id => date => values
     */
    private function sourceValues(int $propertyId, array $products, array $sourceIds, CarbonImmutable $from, CarbonImmutable $to, bool $rates, bool $restrictions): array
    {
        $ids = array_keys($products);
        $rows = [];
        foreach (DB::table('ari_daily')->where('property_id', $propertyId)->whereIn('product_id', $ids)
            ->where('stay_date', '>=', $from->toDateString())->where('stay_date', '<=', $to->toDateString())
            ->get(['product_id', 'stay_date', 'price', 'min_los', 'max_los', 'cta', 'ctd', 'stop_sell', 'cutoff_days', 'max_advance_days']) as $row) {
            $rows[(int) $row->product_id][$row->stay_date] = $row;
        }
        $occupancy = [];
        if ($rates) {
            foreach (DB::table('ari_daily_occupancy')->where('property_id', $propertyId)->whereIn('product_id', $sourceIds)
                ->where('stay_date', '>=', $from->toDateString())->where('stay_date', '<=', $to->toDateString())
                ->get(['product_id', 'stay_date', 'adults', 'price']) as $row) {
                $occupancy[(int) $row->product_id][$row->stay_date][(int) $row->adults] = (string) $row->price;
            }
        }

        $priceOf = function (int $id, string $date, int $depth = 0) use (&$priceOf, $products, $rows): ?string {
            $p = $products[$id] ?? null;
            if ($p === null || $depth > 5) {
                return null;
            }
            if ($p->pricing_mode !== 'derived') {
                $price = $rows[$id][$date]->price ?? null;

                return $price === null ? null : (string) $price;
            }
            $parent = $priceOf((int) $p->parent_product_id, $date, $depth + 1);

            return $parent === null ? null
                : DerivedPrice::apply($parent, (string) $p->adjust_type, (string) $p->adjust_value, (int) $p->base_adults ?: 1);
        };
        // The row that holds the restrictions (a derived product that follows its parent reads the parent's).
        $restrictionRow = function (int $id, string $date, int $depth = 0) use (&$restrictionRow, $products, $rows): ?object {
            $p = $products[$id] ?? null;
            if ($p === null || $depth > 5) {
                return null;
            }
            if ($p->pricing_mode === 'derived' && $p->inherit_restrictions && $p->parent_product_id) {
                return $restrictionRow((int) $p->parent_product_id, $date, $depth + 1);
            }

            return $rows[$id][$date] ?? null;
        };

        $out = [];
        foreach ($sourceIds as $id) {
            if (! isset($products[$id])) {
                continue;
            }
            for ($d = $from; $d->lessThanOrEqualTo($to); $d = $d->addDay()) {
                $date = $d->toDateString();
                $own = $rows[$id][$date] ?? null;
                $r = $restrictionRow($id, $date);
                $price = $rates ? $priceOf($id, $date) : null;
                if (($rates && $price === null) && (! $restrictions || $r === null)) {
                    continue;
                }
                if (! $rates && $r === null) {
                    continue;
                }
                $out[$id][$date] = [
                    'price' => $price,
                    'occupancy' => $products[$id]->pricing_mode === 'derived' ? [] : ($occupancy[$id][$date] ?? []),
                    'min_los' => $r?->min_los !== null ? (int) $r->min_los : null,
                    'max_los' => $r?->max_los !== null ? (int) $r->max_los : null,
                    'cta' => (bool) ($r->cta ?? false),
                    'ctd' => (bool) ($r->ctd ?? false),
                    'cutoff' => $r?->cutoff_days !== null ? (int) $r->cutoff_days : null,
                    'max_advance' => $r?->max_advance_days !== null ? (int) $r->max_advance_days : null,
                    'closed' => (bool) ($own->stop_sell ?? false) || (bool) ($r->stop_sell ?? false),
                ];
                if ($restrictions && $r === null) {
                    // Price only (no stored restrictions for that night): copy the price, leave restrictions alone.
                    $out[$id][$date]['no_restrictions'] = true;
                }
            }
        }

        return $out;
    }
}
