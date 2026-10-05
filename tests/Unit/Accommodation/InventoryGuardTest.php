<?php

namespace Tests\Unit\Accommodation;

use App\Domain\Accommodation\Events\RoomTypeUnitsChanged;
use App\Domain\Accommodation\PhysicalUnitService;
use App\Domain\Accommodation\UnitNameGenerator;
use App\Models\PhysicalUnit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Accommodation\AccommodationTestCase;

/**
 * Inventory = active PMS rooms. Covers room naming, the unit-count events that keep the
 * daily inventory in sync, the guards against selling a room twice and the database
 * constraints that hold when two requests race.
 */
class InventoryGuardTest extends AccommodationTestCase
{
    public function test_unit_names_from_range_sequence_and_quantity(): void
    {
        $names = new UnitNameGenerator;

        $this->assertSame(['101', '102', '103'], $names->generate(['mode' => 'range', 'range' => '101-103'], 'DLX'));
        $this->assertSame(['A08', 'A09', 'A10'], $names->generate(['mode' => 'range', 'range' => 'A08-A10'], 'DLX'));
        $this->assertSame(['V-01', 'V-02'], $names->generate(['mode' => 'sequence', 'prefix' => 'V-', 'start' => '01', 'count' => 2], 'VIL'));
        $this->assertSame(['DLX-03', 'DLX-04'], $names->generate(['mode' => 'quantity', 'quantity' => 2], 'dlx', ['DLX-01', 'DLX-02', '101']));

        $this->expectException(ValidationException::class);
        $names->generate(['mode' => 'range', 'range' => '120-101'], 'DLX');
    }

    public function test_unit_count_changes_dispatch_sync_events(): void
    {
        $roomType = $this->makeRoomType([], 1);
        Event::fake([RoomTypeUnitsChanged::class]);
        $this->inProperty($this->property);
        $service = app(PhysicalUnitService::class);

        $service->bulkCreate($roomType, ['mode' => 'quantity', 'quantity' => 2]);
        $unit = PhysicalUnit::query()->where('room_type_id', $roomType->id)->orderBy('id')->first();
        $service->setActive($unit, false);
        $service->setHousekeeping($unit, 'dirty'); // not an inventory change

        Event::assertDispatchedTimes(RoomTypeUnitsChanged::class, 2);
        $this->assertSame(2, PhysicalUnit::query()->where('room_type_id', $roomType->id)->where('is_active', true)->count());
    }

    public function test_moving_a_room_with_a_future_guest_is_refused(): void
    {
        $from = $this->makeRoomType([], 1);
        $to = $this->makeRoomType([], 0);
        $unit = $this->firstUnit($from);
        $this->reserveNight($unit, date('Y-m-d', strtotime($this->today().' +5 days')));
        $this->inProperty($this->property);

        try {
            app(PhysicalUnitService::class)->update($unit, ['room_type' => $to]);
            $this->fail('Moving an assigned room must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('room_type_id', $e->errors());
        }
        $this->assertSame($from->id, (int) $unit->fresh()->room_type_id);
    }

    public function test_past_guests_do_not_block_deactivation(): void
    {
        $unit = $this->firstUnit($this->makeRoomType([], 1));
        $this->reserveNight($unit, date('Y-m-d', strtotime($this->today().' -3 days')));
        $this->inProperty($this->property);

        $this->assertFalse(app(PhysicalUnitService::class)->setActive($unit, false)->is_active);
    }

    public function test_plan_limit_counts_reactivated_rooms(): void
    {
        $roomType = $this->makeRoomType([], 2);
        $unit = $this->firstUnit($roomType);
        $this->inProperty($this->property);
        $service = app(PhysicalUnitService::class);
        $service->setActive($unit, false);
        \App\Models\SubscriptionPlan::query()->whereKey(\App\Models\Subscription::query()->where('property_id', $this->property->id)->value('plan_id'))->update(['max_units' => 1]);

        $this->expectException(ValidationException::class);
        $service->setActive($unit, true);
    }

    public function test_database_rejects_a_second_room_with_the_same_name(): void
    {
        // Two requests that both passed the name check: the unique index stops the second insert.
        $unit = $this->firstUnit($this->makeRoomType(['code' => 'DLX'], 1));
        $this->inProperty($this->property);

        $this->expectException(QueryException::class);
        PhysicalUnit::query()->create(['room_type_id' => $unit->room_type_id, 'name' => $unit->name, 'is_active' => true]);
    }

    public function test_database_rejects_two_bookings_of_one_room_night(): void
    {
        // unit_nights has (unit_id, stay_date) as primary key: a room night can be sold once.
        $unit = $this->firstUnit($this->makeRoomType([], 1));
        $date = date('Y-m-d', strtotime($this->today().' +1 day'));
        $this->reserveNight($unit, $date);

        $this->expectException(QueryException::class);
        $this->reserveNight($unit, $date);
    }

    public function test_block_takes_a_row_lock_on_the_room(): void
    {
        // The block service locks the room row (SELECT … FOR UPDATE) before checking overlaps, so a
        // second request for the same room waits and then sees the first block.
        $unit = $this->firstUnit($this->makeRoomType([], 1));
        $this->inProperty($this->property);
        $queries = [];
        \Illuminate\Support\Facades\DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });

        app(\App\Domain\Accommodation\UnitBlockService::class)->block($unit, 'maintenance', $this->today(), date('Y-m-d', strtotime($this->today().' +1 day')));

        $this->assertNotEmpty(array_filter($queries, fn ($sql) => str_contains($sql, 'physical_units') && str_contains($sql, 'for update')));
    }
}
