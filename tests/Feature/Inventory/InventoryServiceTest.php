<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\Exceptions\NotAvailableException;
use App\Domain\Inventory\InventoryService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Guarded inventory updates: no overbooking, all-or-nothing, holds, sell limits. */
class InventoryServiceTest extends InventoryTestCase
{
    private InventoryService $inventory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inventory = app(InventoryService::class);
    }

    public function test_reserve_and_release_change_sold_for_every_night(): void
    {
        $roomType = $this->makeRoomType([], 3);
        $before = $this->version();

        $this->inventory->reserve($roomType->id, $this->day(2), $this->day(5), 2);
        $this->assertSame([2, 2, 2], [$this->inv($roomType, $this->day(2))->sold, $this->inv($roomType, $this->day(3))->sold, $this->inv($roomType, $this->day(4))->sold]);
        $this->assertSame(0, $this->inv($roomType, $this->day(5))->sold, 'check-out night is not taken');
        $this->assertGreaterThan($before, $this->version());
        $this->assertSame(['sold' => 2], json_decode((string) DB::table('ari_change_log')->where('room_type_id', $roomType->id)->where('source', 'reservation')->value('payload'), true));

        $this->inventory->release($roomType->id, $this->day(3), $this->day(5), 2);
        $this->assertSame(2, $this->inv($roomType, $this->day(2))->sold);
        $this->assertSame(0, $this->inv($roomType, $this->day(3))->sold);

        // Releasing more than was sold never goes below zero.
        $this->inventory->release($roomType->id, $this->day(2), $this->day(3), 5);
        $this->assertSame(0, $this->inv($roomType, $this->day(2))->sold);
    }

    public function test_reserve_is_all_or_nothing(): void
    {
        $roomType = $this->makeRoomType([], 2);
        $this->inventory->reserve($roomType->id, $this->day(4), $this->day(5), 2);

        try {
            $this->inventory->reserve($roomType->id, $this->day(2), $this->day(6));
            $this->fail('Expected NotAvailableException');
        } catch (NotAvailableException $e) {
            $this->assertSame([$this->day(4)->toDateString()], $e->dates);
            $this->assertSame($roomType->id, $e->roomTypeId);
        }
        $this->assertSame(0, $this->inv($roomType, $this->day(2))->sold, 'no partial update');
        $this->assertSame(0, $this->inv($roomType, $this->day(5))->sold);
    }

    public function test_holds_count_against_availability_and_can_be_confirmed(): void
    {
        $roomType = $this->makeRoomType([], 2);
        $this->inventory->reserve($roomType->id, $this->day(1), $this->day(3), 1, hold: true);
        $this->inventory->reserve($roomType->id, $this->day(1), $this->day(3), 1);
        $this->assertSame([1, 1], [$this->inv($roomType, $this->day(1))->held, $this->inv($roomType, $this->day(1))->sold]);

        $this->expectException(NotAvailableException::class);
        try {
            $this->inventory->reserve($roomType->id, $this->day(1), $this->day(2));
        } finally {
            $this->inventory->confirmHold($roomType->id, $this->day(1), $this->day(3));
            $this->assertSame([0, 2], [$this->inv($roomType, $this->day(2))->held, $this->inv($roomType, $this->day(2))->sold]);
        }
    }

    public function test_out_of_order_rooms_and_sell_limit_reduce_what_can_be_sold(): void
    {
        $roomType = $this->makeRoomType([], 4);
        $this->inventory->blockUnits($roomType->id, $this->day(1), $this->day(2), 1);
        DB::table('inventory_daily')->where('room_type_id', $roomType->id)->where('stay_date', $this->day(1)->toDateString())->update(['sell_limit' => 3]);

        // capacity 3 (sell limit) − 1 out of order = 2
        $this->assertSame([$this->day(1)->toDateString() => 2], $this->inventory->remaining($roomType->id, $this->day(1), $this->day(2)));
        $this->inventory->reserve($roomType->id, $this->day(1), $this->day(2), 2);
        $this->assertSame([$this->day(1)->toDateString() => 0], $this->inventory->remaining($roomType->id, $this->day(1), $this->day(2)));

        $this->expectException(NotAvailableException::class);
        $this->inventory->reserve($roomType->id, $this->day(1), $this->day(2));
    }

    public function test_block_units_is_guarded_and_unblock_never_goes_negative(): void
    {
        $roomType = $this->makeRoomType([], 2);
        $this->inventory->reserve($roomType->id, $this->day(1), $this->day(2), 2);
        try {
            $this->inventory->blockUnits($roomType->id, $this->day(0), $this->day(2));
            $this->fail('Expected NotAvailableException');
        } catch (NotAvailableException $e) {
            $this->assertSame([$this->day(1)->toDateString()], $e->dates);
        }
        $this->assertSame(0, $this->inv($roomType, $this->day(0))->ooo_units);

        $this->inventory->unblockUnits($roomType->id, $this->day(0), $this->day(1), 3);
        $this->assertSame(0, $this->inv($roomType, $this->day(0))->ooo_units);
    }

    public function test_reserve_beyond_the_horizon_creates_the_rows(): void
    {
        $roomType = $this->makeRoomType([], 1);
        $this->inventory->reserve($roomType->id, $this->day(58), $this->day(63));

        $this->assertSame(1, $this->inv($roomType, $this->day(62))->sold);
        $this->assertSame(1, $this->inv($roomType, $this->day(62))->total_units);
        $this->assertSame(1, $this->inv($roomType, $this->day(58))->sold);
    }

    public function test_database_refuses_an_overbooking_write_that_bypasses_the_service(): void
    {
        $roomType = $this->makeRoomType([], 2);

        $this->expectException(QueryException::class);
        DB::table('inventory_daily')->where('room_type_id', $roomType->id)->where('stay_date', $this->day(1)->toDateString())->update(['sold' => 3]);
    }

    public function test_reserve_many_locks_room_types_in_a_fixed_order(): void
    {
        $a = $this->makeRoomType([], 1);
        $b = $this->makeRoomType([], 1);
        $this->inventory->reserveMany([
            ['room_type_id' => $b->id, 'from' => $this->day(1), 'to' => $this->day(2)],
            ['room_type_id' => $a->id, 'from' => $this->day(1), 'to' => $this->day(2)],
        ]);
        $this->assertSame(1, $this->inv($a, $this->day(1))->sold);
        $this->assertSame(1, $this->inv($b, $this->day(1))->sold);

        // The second room type is full, so nothing of the first is taken either.
        $c = $this->makeRoomType([], 1);
        try {
            DB::transaction(fn () => $this->inventory->reserveMany([
                ['room_type_id' => $c->id, 'from' => $this->day(1), 'to' => $this->day(2)],
                ['room_type_id' => $a->id, 'from' => $this->day(1), 'to' => $this->day(2)],
            ]));
            $this->fail('Expected NotAvailableException');
        } catch (NotAvailableException) {
        }
        $this->assertSame(0, $this->inv($c, $this->day(1))->sold);
    }

    public function test_invalid_arguments_are_refused(): void
    {
        $roomType = $this->makeRoomType();
        $this->expectException(\InvalidArgumentException::class);
        $this->inventory->reserve($roomType->id, $this->day(2), $this->day(2));
    }
}
