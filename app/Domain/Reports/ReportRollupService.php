<?php

namespace App\Domain\Reports;

use App\Infrastructure\Database\Tx;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Builds the reporting rollups of one property for a range of stay dates [from, to):
 *
 *   stats_daily      room type × night: rooms in the hotel, out of order, sold, room revenue,
 *                    arrivals, departures, cancellations and no-shows (by arrival date)
 *   stats_daily_mix  room type × rate plan × booking source × night: rooms sold, guests,
 *                    room revenue, discount, tax
 *
 * The range is deleted and rebuilt in one transaction with INSERT … SELECT (a few statements,
 * whatever the number of bookings). Called after every booking change (listener), when rooms are
 * blocked, by the nightly `reports:refresh` and by the demo seeders. Report pages read only these tables
 * (plus the posted ledger for money actually charged and collected).
 *
 * Definitions: a room night is sold when its reservation night is active (pending, confirmed,
 * checked in / out; cancelled, no-show and early-departure nights are inactive). Room revenue =
 * net night price (after discounts, before tax). Rooms available = rooms in the hotel − out of order.
 */
class ReportRollupService
{
    /** Longest range rebuilt in one transaction; longer ranges are split. */
    private const CHUNK_DAYS = 120;

    public function refresh(int $propertyId, CarbonImmutable $from, CarbonImmutable $to): void
    {
        for ($start = $from; $start->lessThan($to); $start = $start->addDays(self::CHUNK_DAYS)) {
            $end = $start->addDays(self::CHUNK_DAYS)->min($to);
            Tx::run(fn () => $this->rebuild($propertyId, $start->toDateString(), $end->toDateString()));
        }
    }

    /** Every date the property has inventory rows for (used by seeders and `reports:refresh --all`). */
    public function refreshAll(int $propertyId): void
    {
        $range = DB::table('inventory_daily')->where('property_id', $propertyId)->selectRaw('MIN(stay_date) AS a, MAX(stay_date) AS b')->first();
        $first = DB::table('reservation_rooms')->where('property_id', $propertyId)->min('check_in');
        $from = collect([$range->a ?? null, $first])->filter()->min();
        if ($from === null) {
            return;
        }
        $to = collect([$range->b ?? null, DB::table('reservation_rooms')->where('property_id', $propertyId)->max('check_out')])->filter()->max();
        $this->refresh($propertyId, CarbonImmutable::parse($from), CarbonImmutable::parse($to)->addDay());
    }

    private function rebuild(int $propertyId, string $from, string $to): void
    {
        $now = now()->format('Y-m-d H:i:s');
        DB::table('stats_daily')->where('property_id', $propertyId)->where('stay_date', '>=', $from)->where('stay_date', '<', $to)->delete();
        DB::table('stats_daily_mix')->where('property_id', $propertyId)->where('stay_date', '>=', $from)->where('stay_date', '<', $to)->delete();

        // Base rows: every room type and night with inventory, plus nights that have bookings
        // but no inventory row (e.g. archived dates).
        DB::insert(<<<'SQL'
INSERT INTO stats_daily (property_id, stay_date, room_type_id, units_total, units_ooo, rooms_sold, room_revenue,
                         arrivals, departures, cancellations, no_shows, refreshed_at)
SELECT k.property_id, k.stay_date, k.room_type_id,
       COALESCE(i.total_units, 0), COALESCE(i.ooo_units, 0),
       COALESCE(n.sold, 0), COALESCE(n.revenue, 0),
       COALESCE(a.arrivals, 0), COALESCE(d.departures, 0), COALESCE(a.cancellations, 0), COALESCE(a.no_shows, 0), ?
FROM (
    SELECT property_id, stay_date, room_type_id FROM inventory_daily
     WHERE property_id = ? AND stay_date >= ? AND stay_date < ?
    UNION
    SELECT property_id, stay_date, room_type_id FROM reservation_room_nights
     WHERE property_id = ? AND stay_date >= ? AND stay_date < ? AND is_active = 1
    UNION
    SELECT property_id, check_in, room_type_id FROM reservation_rooms
     WHERE property_id = ? AND check_in >= ? AND check_in < ?
    UNION
    SELECT property_id, check_out, room_type_id FROM reservation_rooms
     WHERE property_id = ? AND check_out >= ? AND check_out < ? AND status NOT IN ('hold', 'cancelled', 'no_show')
) k
LEFT JOIN inventory_daily i ON i.room_type_id = k.room_type_id AND i.stay_date = k.stay_date
LEFT JOIN (
    SELECT stay_date, room_type_id, COUNT(*) AS sold, SUM(net_price) AS revenue
      FROM reservation_room_nights
     WHERE property_id = ? AND stay_date >= ? AND stay_date < ? AND is_active = 1
     GROUP BY stay_date, room_type_id
) n ON n.stay_date = k.stay_date AND n.room_type_id = k.room_type_id
LEFT JOIN (
    SELECT check_in, room_type_id,
           SUM(status IN ('pending', 'confirmed', 'checked_in', 'checked_out')) AS arrivals,
           SUM(status = 'cancelled') AS cancellations,
           SUM(status = 'no_show') AS no_shows
      FROM reservation_rooms
     WHERE property_id = ? AND check_in >= ? AND check_in < ?
     GROUP BY check_in, room_type_id
) a ON a.check_in = k.stay_date AND a.room_type_id = k.room_type_id
LEFT JOIN (
    SELECT check_out, room_type_id, COUNT(*) AS departures
      FROM reservation_rooms
     WHERE property_id = ? AND check_out >= ? AND check_out < ? AND status IN ('pending', 'confirmed', 'checked_in', 'checked_out')
     GROUP BY check_out, room_type_id
) d ON d.check_out = k.stay_date AND d.room_type_id = k.room_type_id
SQL, [$now,
            $propertyId, $from, $to, $propertyId, $from, $to, $propertyId, $from, $to, $propertyId, $from, $to,
            $propertyId, $from, $to, $propertyId, $from, $to, $propertyId, $from, $to]);

        DB::insert(<<<'SQL'
INSERT INTO stats_daily_mix (property_id, stay_date, room_type_id, rate_plan_id, source_id, rooms_sold, guests,
                             room_revenue, discount, tax, refreshed_at)
SELECT n.property_id, n.stay_date, n.room_type_id, rr.rate_plan_id, r.source_id,
       COUNT(*), SUM(rr.adults + rr.children), SUM(n.net_price), SUM(n.discount), SUM(n.tax_amount), ?
  FROM reservation_room_nights n
  JOIN reservation_rooms rr ON rr.id = n.reservation_room_id
  JOIN reservations r ON r.id = rr.reservation_id
 WHERE n.property_id = ? AND n.stay_date >= ? AND n.stay_date < ? AND n.is_active = 1
 GROUP BY n.property_id, n.stay_date, n.room_type_id, rr.rate_plan_id, r.source_id
SQL, [$now, $propertyId, $from, $to]);
    }
}
