<?php

namespace App\Domain\Reservations;

use App\Domain\Inventory\Exceptions\NotAvailableException;
use App\Domain\Inventory\InventoryService;
use Carbon\CarbonImmutable;

/**
 * Turns "rooms per room type per night before" and "after" into the smallest set of
 * InventoryService calls: releases first (they only free rooms), then one reserveMany() with
 * runs of consecutive nights, which locks rows in (room type, date) order. Used for create
 * (before = empty), modify and cancel (after = empty), so inventory_daily.sold always equals
 * the active reservation_room_nights.
 */
class InventoryDelta
{
    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * @param  array<int, array<string, int>>  $before  room_type_id => [date => rooms]
     * @param  array<int, array<string, int>>  $after
     *
     * @throws NotAvailableException
     */
    public function apply(array $before, array $after): void
    {
        $take = [];
        $give = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $roomTypeId) {
            $dates = array_unique([...array_keys($before[$roomTypeId] ?? []), ...array_keys($after[$roomTypeId] ?? [])]);
            sort($dates);
            foreach ($dates as $date) {
                $diff = ($after[$roomTypeId][$date] ?? 0) - ($before[$roomTypeId][$date] ?? 0);
                if ($diff > 0) {
                    $take[$roomTypeId][$date] = $diff;
                } elseif ($diff < 0) {
                    $give[$roomTypeId][$date] = -$diff;
                }
            }
        }

        foreach ($give as $roomTypeId => $nights) {
            foreach ($this->runs($nights) as [$from, $to, $rooms]) {
                $this->inventory->release((int) $roomTypeId, $from, $to, $rooms);
            }
        }

        $items = [];
        foreach ($take as $roomTypeId => $nights) {
            foreach ($this->runs($nights) as [$from, $to, $rooms]) {
                $items[] = ['room_type_id' => (int) $roomTypeId, 'from' => $from, 'to' => $to, 'rooms' => $rooms];
            }
        }
        if ($items !== []) {
            $this->inventory->reserveMany($items);
        }
    }

    /**
     * Consecutive nights with the same room count.
     *
     * @param  array<string, int>  $nights  date => rooms (sorted)
     * @return list<array{CarbonImmutable, CarbonImmutable, int}>
     */
    public function runs(array $nights): array
    {
        ksort($nights);
        $runs = [];
        $start = $prev = null;
        $count = 0;
        foreach ($nights as $date => $rooms) {
            $d = CarbonImmutable::parse($date);
            if ($start !== null && ($rooms !== $count || ! $prev->addDay()->equalTo($d))) {
                $runs[] = [$start, $prev->addDay(), $count];
                $start = null;
            }
            if ($start === null) {
                $start = $d;
                $count = $rooms;
            }
            $prev = $d;
        }
        if ($start !== null) {
            $runs[] = [$start, $prev->addDay(), $count];
        }

        return $runs;
    }
}
