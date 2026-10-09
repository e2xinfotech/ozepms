<?php

namespace App\Domain\Channels;

use App\Domain\Channels\Data\AriUpdate;

/**
 * Shapes a list of ARI updates so an adapter sends as few calls as the channel allows.
 *
 *  - merge():   one value set per room / rate / date (later updates win field by field), then every run of consecutive
 *               days with the same values becomes one range. Duplicates and overlaps disappear.
 *  - windows(): cuts ranges to at most N days.
 *  - months():  cuts ranges at calendar month ends, grouped by month.
 *  - groupByValues(): same room + rate + values together (for channels that take a list of dates).
 * The adapter then loops over the result and decides how many requests it needs for its own limits.
 */
final class AriBatcher
{
    /**
     * @param  list<AriUpdate>  $updates
     * @return list<AriUpdate> sorted by room, rate (room-level first), start date
     */
    public static function merge(array $updates): array
    {
        /** @var array<string, array<string, array<string, mixed>>> $perDay key "room|rate" => date => values */
        $perDay = [];
        $ids = [];
        foreach ($updates as $u) {
            $key = $u->roomId."\x1f".($u->rateId ?? '');
            $ids[$key] = [$u->roomId, $u->rateId];
            for ($d = strtotime($u->from); $d <= strtotime($u->to); $d += 86400) {
                $date = date('Y-m-d', $d);
                $perDay[$key][$date] = array_filter($u->values(), fn ($v) => $v !== null) + ($perDay[$key][$date] ?? []);
            }
        }
        ksort($perDay);
        $out = [];
        foreach ($perDay as $key => $days) {
            ksort($days);
            [$room, $rate] = $ids[$key];
            $start = $prev = $current = null;
            $flush = function () use (&$out, &$start, &$prev, &$current, $room, $rate) {
                if ($start !== null) {
                    $out[] = new AriUpdate($room, $rate, $start, $prev, $current['availability'] ?? null, $current['price'] ?? null, $current['min_los'] ?? null,
                        $current['max_los'] ?? null, $current['cta'] ?? null, $current['ctd'] ?? null, $current['stop_sell'] ?? null);
                }
            };
            foreach ($days as $date => $values) {
                ksort($values);
                $contiguous = $prev !== null && date('Y-m-d', strtotime($prev.' +1 day')) === $date;
                if ($start === null || ! $contiguous || $values !== $current) {
                    $flush();
                    $start = $date;
                    $current = $values;
                }
                $prev = $date;
            }
            $flush();
        }

        return $out;
    }

    /**
     * @param  list<AriUpdate>  $updates
     * @return list<AriUpdate> each range cut into pieces of at most $maxDays
     */
    public static function windows(array $updates, int $maxDays): array
    {
        $out = [];
        foreach ($updates as $u) {
            for ($d = strtotime($u->from); $d <= strtotime($u->to); $d = strtotime(date('Y-m-d', $d).' +'.$maxDays.' days')) {
                $to = date('Y-m-d', min(strtotime($u->to), strtotime(date('Y-m-d', $d).' +'.($maxDays - 1).' days')));
                $out[] = new AriUpdate($u->roomId, $u->rateId, date('Y-m-d', $d), $to, $u->availability, $u->price, $u->minLos, $u->maxLos, $u->cta, $u->ctd, $u->stopSell);
            }
        }

        return $out;
    }

    /**
     * @param  list<AriUpdate>  $updates
     * @return array<string, list<AriUpdate>> "Y-m" => updates inside that month, in month order
     */
    public static function months(array $updates): array
    {
        $out = [];
        foreach ($updates as $u) {
            $day = strtotime($u->from);
            $end = strtotime($u->to);
            while ($day <= $end) {
                $monthEnd = min($end, strtotime(date('Y-m-t', $day)));
                $out[date('Y-m', $day)][] = new AriUpdate($u->roomId, $u->rateId, date('Y-m-d', $day), date('Y-m-d', $monthEnd), $u->availability, $u->price,
                    $u->minLos, $u->maxLos, $u->cta, $u->ctd, $u->stopSell);
                $day = strtotime(date('Y-m-d', $monthEnd).' +1 day');
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * Same room, rate and values together.
     *
     * @param  list<AriUpdate>  $updates
     * @return list<array{room: string, rate: ?string, values: array<string, mixed>, ranges: list<array{0: string, 1: string}>}>
     */
    public static function groupByValues(array $updates): array
    {
        $groups = [];
        foreach ($updates as $u) {
            $values = array_filter($u->values(), fn ($v) => $v !== null);
            ksort($values);
            $key = $u->roomId."\x1f".($u->rateId ?? '')."\x1f".json_encode($values);
            $groups[$key] ??= ['room' => $u->roomId, 'rate' => $u->rateId, 'values' => $values, 'ranges' => []];
            $groups[$key]['ranges'][] = [$u->from, $u->to];
        }

        return array_values($groups);
    }

    /** @return list<string> every date of the ranges, sorted and without duplicates */
    public static function dates(array $ranges): array
    {
        $all = [];
        foreach ($ranges as [$from, $to]) {
            for ($d = strtotime($from); $d <= strtotime($to); $d += 86400) {
                $all[date('Y-m-d', $d)] = true;
            }
        }
        ksort($all);

        return array_keys($all);
    }
}
