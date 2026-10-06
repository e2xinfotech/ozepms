<?php

namespace App\Domain\Inventory\Queries;

use App\Domain\Inventory\AvailabilityLevel;
use App\Domain\Rates\DerivedPrice;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Year overview of the calendar: twelve months from a chosen month, one line per room type with
 * an availability level and the lowest sellable price for every night.
 *
 * Aggregated on the server so the page receives one short string of levels and one list of prices
 * per room type (not every room type × rate plan × night cell):
 *
 *   levels  one character per night: "0" sold out, "1" low, "2" available, "s" stop sell,
 *           "." no data (no rooms, or outside the stored horizon)
 *   prices  lowest price that night among the rate plans shown and open (not closed); null = none
 *   month_min  lowest of those prices per month
 *
 * Reads three indexed ranges whatever the size of the property: inventory_daily and ari_daily by
 * (property_id, stay_date) for the twelve months, plus the small room type / product tables.
 * Filters: room_type, rate_plan (prices of that rate plan only), status (active / all / inactive).
 */
final class CalendarYearQuery
{
    public const MONTHS = 12;

    /** First day of the twelve months that contain $date (the month itself and the next eleven). */
    public static function resolveStart(?string $date, Property $property): CarbonImmutable
    {
        [$from] = CalendarQuery::resolveWindow('month', $date, $property);

        return $from;
    }

    /**
     * @param  array<string, mixed>  $filters  see CalendarQuery::normalizeFilters()
     * @return array<string, mixed>
     */
    public function year(Property $property, CarbonImmutable $from, array $filters = []): array
    {
        $filters = CalendarQuery::normalizeFilters($filters);
        $from = $from->startOfMonth()->startOfDay();
        $to = $from->addMonths(self::MONTHS);                 // exclusive
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();
        $pid = (int) $property->id;
        $status = (string) ($filters['status'] ?? '');
        $withInactive = in_array($status, ['all', 'inactive'], true);
        $today = CarbonImmutable::now($property->timezone ?: 'UTC')->toDateString();

        $dates = [];
        for ($d = $from; $d < $to; $d = $d->addDay()) {
            $dates[] = $d->toDateString();
        }
        $index = array_flip($dates);
        $count = count($dates);

        $roomTypes = DB::table('room_types')
            ->where('property_id', $pid)->whereNull('deleted_at')
            ->when(! $withInactive, fn ($q) => $q->where('is_active', 1))
            ->when($status === 'inactive', fn ($q) => $q->where('is_active', 0))
            ->when($filters['room_type'] !== null, fn ($q) => $q->where('public_id', $filters['room_type']))
            ->when($filters['unit'] !== null, fn ($q) => $q->whereIn('id', DB::table('physical_units')
                ->where('property_id', $pid)->where('public_id', $filters['unit'])->select('room_type_id')))
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'public_id', 'code', 'name', 'base_adults', 'is_active']);
        $roomTypeIds = $roomTypes->pluck('id')->all();

        $products = $roomTypeIds === [] ? collect() : DB::table('room_type_rate_plans as p')
            ->join('rate_plans as rp', 'rp.id', '=', 'p.rate_plan_id')
            ->where('p.property_id', $pid)->whereIn('p.room_type_id', $roomTypeIds)->whereNull('rp.deleted_at')
            ->get(['p.id', 'p.room_type_id', 'p.pricing_mode', 'p.parent_product_id', 'p.adjust_type', 'p.adjust_value',
                'p.inherit_restrictions', 'p.is_active', 'rp.public_id as rp_public_id', 'rp.is_active as rp_active']);
        $byId = $products->keyBy('id')->all();
        // Products whose prices are shown: open ones (and the chosen rate plan only, when filtered).
        $shown = $products->filter(fn ($p) => ($withInactive || ($p->is_active && $p->rp_active))
            && ($filters['rate_plan'] === null || $p->rp_public_id === $filters['rate_plan']))->values();

        // Daily availability per room type.
        $inventory = [];
        if ($roomTypeIds !== []) {
            foreach (DB::table('inventory_daily')->where('property_id', $pid)->where('stay_date', '>=', $fromDate)->where('stay_date', '<', $toDate)
                ->whereIn('room_type_id', $roomTypeIds)
                ->get(['room_type_id', 'stay_date', 'total_units', 'ooo_units', 'sold', 'held', 'sell_limit', 'stop_sell']) as $row) {
                $inventory[$row->room_type_id][$index[$row->stay_date]] = $row;
            }
        }

        // Daily prices and product stop sell of the shown products and the parents they derive from.
        $needed = [];
        foreach ($shown as $p) {
            $guard = 0;
            for ($cur = $p; $cur !== null && $guard++ < 5; $cur = $cur->pricing_mode === 'derived' ? ($byId[$cur->parent_product_id] ?? null) : null) {
                $needed[$cur->id] = true;
            }
        }
        $price = [];
        $closed = [];
        if ($needed !== []) {
            foreach (DB::table('ari_daily')->where('property_id', $pid)->where('stay_date', '>=', $fromDate)->where('stay_date', '<', $toDate)
                ->whereIn('product_id', array_keys($needed))
                ->get(['product_id', 'stay_date', 'price', 'stop_sell']) as $row) {
                $i = $index[$row->stay_date];
                if ($row->price !== null) {
                    $price[$row->product_id][$i] = (string) $row->price;
                }
                if ($row->stop_sell) {
                    $closed[$row->product_id][$i] = true;
                }
            }
        }
        $basePersons = $roomTypes->pluck('base_adults', 'id')->all();
        $priceOf = function (object $p, int $i, int $depth = 0) use (&$priceOf, $byId, $price, $basePersons): ?string {
            if ($p->pricing_mode !== 'derived') {
                return $price[$p->id][$i] ?? null;
            }
            $parent = $byId[$p->parent_product_id] ?? null;
            if ($parent === null || $depth > 4) {
                return null;
            }
            $parentPrice = $priceOf($parent, $i, $depth + 1);

            return $parentPrice === null ? null
                : DerivedPrice::apply($parentPrice, (string) $p->adjust_type, (string) $p->adjust_value, (int) ($basePersons[$p->room_type_id] ?? 1) ?: 1);
        };

        // Closed (stop sell) that night; derived products that follow the parent's restrictions are
        // closed with it, as on the month view.
        $isClosed = function (object $p, int $i, int $depth = 0) use (&$isClosed, $byId, $closed): bool {
            if (isset($closed[$p->id][$i])) {
                return true;
            }
            $parent = $p->pricing_mode === 'derived' && $p->inherit_restrictions ? ($byId[$p->parent_product_id] ?? null) : null;

            return $parent !== null && $depth < 4 && $isClosed($parent, $i, $depth + 1);
        };

        $months = [];
        $monthOf = [];
        for ($m = $from, $k = 0; $m < $to; $m = $m->addMonth(), $k++) {
            $months[] = ['month' => $m->format('Y-m'), 'days' => (int) $m->daysInMonth, 'first_dow' => (int) $m->isoWeekday(), 'start' => $index[$m->toDateString()]];
            for ($j = 0; $j < $m->daysInMonth; $j++) {
                $monthOf[] = $k;
            }
        }

        $shownByType = $shown->groupBy('room_type_id');
        $rows = [];
        foreach ($roomTypes as $rt) {
            $levels = '';
            $prices = [];
            $monthMin = array_fill(0, count($months), null);
            $typeProducts = $shownByType->get($rt->id, collect());
            for ($i = 0; $i < $count; $i++) {
                $inv = $inventory[$rt->id][$i] ?? null;
                if ($inv === null || (int) $inv->total_units === 0) {
                    $levels .= '.';
                } elseif ($inv->stop_sell) {
                    $levels .= 's';
                } else {
                    $t = (int) $inv->total_units;
                    $available = max(0, min($t, $inv->sell_limit !== null ? (int) $inv->sell_limit : $t) - (int) $inv->ooo_units - (int) $inv->sold - (int) $inv->held);
                    $levels .= match (AvailabilityLevel::of($available, $t)) {
                        AvailabilityLevel::SOLD_OUT => '0',
                        AvailabilityLevel::LOW => '1',
                        default => '2',
                    };
                }

                $min = null;
                foreach ($typeProducts as $p) {
                    if ($isClosed($p, $i)) {
                        continue;
                    }
                    $value = $priceOf($p, $i);
                    if ($value !== null && ($min === null || bccomp($value, $min, 2) < 0)) {
                        $min = $value;
                    }
                }
                $prices[] = $min === null ? null : self::short($min);
                $k = $monthOf[$i];
                if ($min !== null && ($monthMin[$k] === null || bccomp($min, $monthMin[$k], 2) < 0)) {
                    $monthMin[$k] = $min;
                }
            }

            $rows[] = [
                'id' => $rt->public_id,
                'code' => $rt->code,
                'name' => $rt->name,
                'is_active' => (bool) $rt->is_active,
                'levels' => $levels,
                'prices' => $prices,
                'month_min' => array_map(fn ($v) => $v === null ? null : self::short($v), $monthMin),
            ];
        }

        return [
            'from' => $fromDate,
            'to' => $to->subDay()->toDateString(),
            'today' => $today,
            'currency' => $property->currency_code,
            'months' => $months,
            'room_types' => $rows,
        ];
    }

    /** "450.00" → "450", "452.50" → "452.5" (shorter payload; the page formats numbers itself). */
    private static function short(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}
