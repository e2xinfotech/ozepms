<?php

namespace Tests\Feature\Accommodation;

use App\Domain\Accommodation\Events\RoomTypeUnitsChanged;
use App\Domain\Accommodation\Events\UnitBlockChanged;
use App\Models\PhysicalUnit;
use App\Models\UnitBlock;
use Illuminate\Support\Facades\Event;

class RoomsTest extends AccommodationTestCase
{
    public function test_list_page_shows_rooms_with_status_counts(): void
    {
        $roomType = $this->makeRoomType(['code' => 'DLX'], 3);
        $this->reserveNight($this->firstUnit($roomType), $this->today());

        $response = $this->actingAs($this->owner)->get($this->page('/rooms'))->assertOk()
            ->assertSee($this->pageName('property/rooms/index'), false)->assertSee('DLX-01', false)->assertSee('John Smith', false);
        $this->assertStringContainsString('"occupied":1', $response->getContent());
        $this->assertStringContainsString('"available":2', $response->getContent());
    }

    public function test_list_tab_filters_rows(): void
    {
        $roomType = $this->makeRoomType(['code' => 'SUP'], 2);
        $this->reserveNight($this->firstUnit($roomType), $this->today());

        $this->actingAs($this->owner)->get($this->page('/rooms?tab=available'))->assertOk()
            ->assertSee('SUP-02', false)->assertDontSee('"name":"SUP-01"', false);
    }

    public function test_list_page_requires_permission(): void
    {
        $this->actingAs($this->member('guest_relations'))->get($this->page('/rooms'))->assertForbidden();
        $this->actingAs($this->member('housekeeping'))->get($this->page('/rooms'))->assertOk();
    }

    public function test_show_returns_panel_data(): void
    {
        $roomType = $this->makeRoomType(['code' => 'FAM', 'name' => 'Family Suite'], 1);
        $unit = $this->firstUnit($roomType);

        $this->actingAs($this->owner)->getJson($this->api('/rooms/'.$unit->public_id))->assertOk()
            ->assertJsonPath('room.name', 'FAM-01')
            ->assertJsonPath('room.room_type.name', 'Family Suite')
            ->assertJsonPath('room.status', 'available')
            ->assertJsonPath('room.housekeeping_status', 'clean');
    }

    public function test_rooms_of_another_property_are_not_found(): void
    {
        $foreign = $this->firstUnit($this->makeRoomType([], 1, $this->other));

        $this->actingAs($this->owner)->getJson($this->api('/rooms/'.$foreign->public_id))->assertNotFound();
        $this->actingAs($this->owner)->putJson($this->api('/rooms/'.$foreign->public_id), ['name' => 'X'])->assertNotFound();
        $this->actingAs($this->owner)->postJson($this->api('/rooms/'.$foreign->public_id.'/status'), ['is_active' => false])->assertNotFound();
        $this->actingAs($this->owner)->postJson($this->api('/rooms/'.$foreign->public_id.'/housekeeping'), ['housekeeping_status' => 'dirty'])->assertNotFound();
        $this->actingAs($this->owner)->postJson($this->api('/rooms/'.$foreign->public_id.'/blocks'), [
            'block_type' => 'maintenance', 'start_date' => $this->today(), 'end_date' => date('Y-m-d', strtotime($this->today().' +2 days')),
        ])->assertNotFound();
    }

    public function test_store_adds_one_room(): void
    {
        Event::fake([RoomTypeUnitsChanged::class]);
        $roomType = $this->makeRoomType([], 0);

        $this->actingAs($this->owner)->postJson($this->api('/rooms'), ['room_type_id' => $roomType->public_id, 'name' => '101', 'floor' => '1'])
            ->assertCreated()->assertJsonPath('room.name', '101')->assertJsonPath('room.floor', '1');
        Event::assertDispatched(RoomTypeUnitsChanged::class, fn ($e) => $e->roomTypeId === $roomType->id);
    }

    public function test_store_validates_and_rejects_duplicate_names(): void
    {
        $roomType = $this->makeRoomType(['code' => 'DLX'], 1);

        $this->actingAs($this->owner)->postJson($this->api('/rooms'), ['room_type_id' => $roomType->public_id])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['name']]]);
        $this->actingAs($this->owner)->postJson($this->api('/rooms'), ['room_type_id' => $roomType->public_id, 'name' => 'DLX-01'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['name']]]);
    }

    public function test_store_rejects_room_type_of_another_property(): void
    {
        $foreign = $this->makeRoomType([], 0, $this->other);

        $this->actingAs($this->owner)->postJson($this->api('/rooms'), ['room_type_id' => $foreign->public_id, 'name' => '901'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['room_type_id']]]);
    }

    public function test_bulk_store_creates_a_range(): void
    {
        $roomType = $this->makeRoomType([], 0);

        $this->actingAs($this->owner)->postJson($this->api('/rooms/bulk'), ['room_type_id' => $roomType->public_id, 'mode' => 'range', 'range' => '201-205', 'floor' => '2'])
            ->assertCreated()->assertJsonPath('rooms', ['201', '202', '203', '204', '205']);
        $this->assertSame(5, PhysicalUnit::acrossProperties()->where('room_type_id', $roomType->id)->where('floor', '2')->count());

        $this->actingAs($this->owner)->postJson($this->api('/rooms/bulk'), ['room_type_id' => $roomType->public_id, 'mode' => 'range', 'range' => 'abc'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['range']]]);
    }

    public function test_bulk_store_is_all_or_nothing(): void
    {
        $roomType = $this->makeRoomType([], 0);
        $this->actingAs($this->owner)->postJson($this->api('/rooms'), ['room_type_id' => $roomType->public_id, 'name' => '303'])->assertCreated();

        $this->actingAs($this->owner)->postJson($this->api('/rooms/bulk'), ['room_type_id' => $roomType->public_id, 'mode' => 'range', 'range' => '301-305'])
            ->assertStatus(422);
        $this->assertSame(1, PhysicalUnit::acrossProperties()->where('room_type_id', $roomType->id)->count());
    }

    public function test_create_is_forbidden_without_permission(): void
    {
        $roomType = $this->makeRoomType([], 0);

        $this->actingAs($this->member('front_desk'))->postJson($this->api('/rooms'), ['room_type_id' => $roomType->public_id, 'name' => '1'])->assertForbidden();
        $this->actingAs($this->member('housekeeping'))->postJson($this->api('/rooms/bulk'), ['room_type_id' => $roomType->public_id, 'mode' => 'quantity', 'quantity' => 2])->assertForbidden();
    }

    public function test_update_renames_and_moves_room(): void
    {
        $from = $this->makeRoomType(['code' => 'AAA'], 1);
        $to = $this->makeRoomType(['code' => 'BBB'], 0);
        $unit = $this->firstUnit($from);

        $this->actingAs($this->owner)->putJson($this->api('/rooms/'.$unit->public_id), ['name' => '501', 'notes' => 'Corner room', 'room_type_id' => $to->public_id])
            ->assertOk()->assertJsonPath('room.name', '501')->assertJsonPath('room.room_type.code', 'BBB')->assertJsonPath('room.notes', 'Corner room');
    }

    public function test_deactivation_is_refused_while_a_guest_is_assigned(): void
    {
        $unit = $this->firstUnit($this->makeRoomType([], 2));
        $this->reserveNight($unit, date('Y-m-d', strtotime($this->today().' +3 days')));

        $this->actingAs($this->owner)->postJson($this->api('/rooms/'.$unit->public_id.'/status'), ['is_active' => false])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['is_active']]]);
        $this->assertTrue($unit->fresh()->is_active);
    }

    public function test_deactivate_and_reactivate_room(): void
    {
        Event::fake([RoomTypeUnitsChanged::class]);
        $unit = $this->firstUnit($this->makeRoomType([], 2));

        $this->actingAs($this->owner)->postJson($this->api('/rooms/'.$unit->public_id.'/status'), ['is_active' => false])
            ->assertOk()->assertJsonPath('room.status', 'inactive');
        $this->actingAs($this->owner)->postJson($this->api('/rooms/'.$unit->public_id.'/status'), ['is_active' => true])
            ->assertOk()->assertJsonPath('room.status', 'available');
        Event::assertDispatchedTimes(RoomTypeUnitsChanged::class, 3);
    }

    public function test_housekeeping_staff_can_change_cleaning_status_only(): void
    {
        $unit = $this->firstUnit($this->makeRoomType([], 1));
        $housekeeping = $this->member('housekeeping');

        $this->actingAs($housekeeping)->postJson($this->api('/rooms/'.$unit->public_id.'/housekeeping'), ['housekeeping_status' => 'dirty'])
            ->assertOk()->assertJsonPath('room.housekeeping_status', 'dirty');
        $this->actingAs($housekeeping)->postJson($this->api('/rooms/'.$unit->public_id.'/housekeeping'), ['housekeeping_status' => 'sparkling'])
            ->assertStatus(422);
        $this->actingAs($housekeeping)->putJson($this->api('/rooms/'.$unit->public_id), ['name' => 'X'])->assertForbidden();
        $this->actingAs($this->member('guest_relations'))->postJson($this->api('/rooms/'.$unit->public_id.'/housekeeping'), ['housekeeping_status' => 'clean'])->assertForbidden();
    }

    public function test_block_room_and_release(): void
    {
        Event::fake([UnitBlockChanged::class]);
        $unit = $this->firstUnit($this->makeRoomType([], 1));
        $from = $this->today();
        $to = date('Y-m-d', strtotime($from.' +3 days'));

        $this->actingAs($this->owner)->postJson($this->api('/rooms/'.$unit->public_id.'/blocks'), ['block_type' => 'maintenance', 'start_date' => $from, 'end_date' => $to, 'reason' => 'AC repair'])
            ->assertCreated()->assertJsonPath('room.status', 'out_of_service')->assertJsonPath('room.blocks.0.reason', 'AC repair');
        Event::assertDispatched(UnitBlockChanged::class, fn ($e) => $e->from->toDateString() === $from && $e->to->toDateString() === $to);

        $this->actingAs($this->owner)->postJson($this->api('/rooms/'.$unit->public_id.'/blocks'), ['block_type' => 'out_of_order', 'start_date' => $from, 'end_date' => $to])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['start_date']]]);

        $block = UnitBlock::acrossProperties()->where('unit_id', $unit->id)->firstOrFail();
        $this->actingAs($this->owner)->deleteJson($this->api('/rooms/'.$unit->public_id.'/blocks/'.$block->id))
            ->assertOk()->assertJsonPath('room.status', 'available');
    }

    public function test_block_validation_and_reserved_nights(): void
    {
        $unit = $this->firstUnit($this->makeRoomType([], 1));
        $from = $this->today();
        $to = date('Y-m-d', strtotime($from.' +2 days'));

        $this->actingAs($this->owner)->postJson($this->api('/rooms/'.$unit->public_id.'/blocks'), ['block_type' => 'maintenance', 'start_date' => $to, 'end_date' => $from])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['end_date']]]);

        $this->reserveNight($unit, $from);
        $this->actingAs($this->owner)->postJson($this->api('/rooms/'.$unit->public_id.'/blocks'), ['block_type' => 'maintenance', 'start_date' => $from, 'end_date' => $to])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['start_date']]]);
    }
}
