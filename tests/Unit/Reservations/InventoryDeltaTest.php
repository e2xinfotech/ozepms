<?php

namespace Tests\Unit\Reservations;

use App\Domain\Inventory\InventoryService;
use App\Domain\Reservations\InventoryDelta;
use PHPUnit\Framework\TestCase;

class InventoryDeltaTest extends TestCase
{
    public function test_runs_group_consecutive_nights_with_the_same_rooms(): void
    {
        $delta = new InventoryDelta($this->createMock(InventoryService::class));
        $runs = $delta->runs(['2026-10-03' => 1, '2026-10-01' => 1, '2026-10-02' => 1, '2026-10-05' => 1, '2026-10-06' => 2]);
        $this->assertSame([
            ['2026-10-01', '2026-10-04', 1], ['2026-10-05', '2026-10-06', 1], ['2026-10-06', '2026-10-07', 2],
        ], array_map(fn ($r) => [$r[0]->toDateString(), $r[1]->toDateString(), $r[2]], $runs));
    }

    public function test_only_the_difference_is_released_and_reserved(): void
    {
        $inventory = $this->createMock(InventoryService::class);
        $inventory->expects($this->once())->method('release')
            ->with(7, $this->callback(fn ($d) => $d->toDateString() === '2026-10-01'), $this->callback(fn ($d) => $d->toDateString() === '2026-10-02'), 1);
        $inventory->expects($this->once())->method('reserveMany')->with($this->callback(fn ($items) => count($items) === 2
            && $items[0]['room_type_id'] === 7 && $items[0]['from']->toDateString() === '2026-10-04' && $items[0]['to']->toDateString() === '2026-10-05'
            && $items[1]['room_type_id'] === 9 && $items[1]['rooms'] === 2));

        (new InventoryDelta($inventory))->apply(
            [7 => ['2026-10-01' => 1, '2026-10-02' => 1, '2026-10-03' => 1]],
            [7 => ['2026-10-02' => 1, '2026-10-03' => 1, '2026-10-04' => 1], 9 => ['2026-10-02' => 2]],
        );
    }
}
