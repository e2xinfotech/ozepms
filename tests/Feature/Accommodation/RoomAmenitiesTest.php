<?php

namespace Tests\Feature\Accommodation;

use App\Models\Amenity;
use Illuminate\Support\Facades\DB;

class RoomAmenitiesTest extends AccommodationTestCase
{
    public function test_room_inherits_room_type_amenities_and_stores_only_differences(): void
    {
        $roomType = $this->makeRoomType(['amenities' => ['wifi', 'tv']], 1);
        $unit = $this->firstUnit($roomType);

        $this->actingAs($this->owner)->putJson($this->api('/rooms/'.$unit->public_id), ['amenities' => ['wifi', 'bathtub']])->assertOk();

        $rows = DB::table('physical_unit_amenities')->join('amenities', 'amenities.id', '=', 'physical_unit_amenities.amenity_id')
            ->where('unit_id', $unit->id)->pluck('physical_unit_amenities.mode', 'amenities.code')->all();
        $this->assertSame(['bathtub' => 'add', 'tv' => 'remove'], collect($rows)->sortKeys()->all());

        $panel = $this->actingAs($this->owner)->getJson($this->api('/rooms/'.$unit->public_id))->assertOk()->json('room.amenities');
        $this->assertEqualsCanonicalizing(['wifi', 'bathtub'], array_column($panel, 'code'));
        $this->assertSame('room', collect($panel)->firstWhere('code', 'bathtub')['source']);
        $this->assertSame('room_type', collect($panel)->firstWhere('code', 'wifi')['source']);
    }

    public function test_new_room_can_be_created_with_its_own_amenities(): void
    {
        $roomType = $this->makeRoomType(['amenities' => ['wifi']], 0);

        $id = $this->actingAs($this->owner)->postJson($this->api('/rooms'), [
            'room_type_id' => $roomType->public_id, 'name' => '701', 'amenities' => ['wifi', 'balcony'],
        ])->assertCreated()->json('room.id');

        $codes = array_column($this->actingAs($this->owner)->getJson($this->api('/rooms/'.$id))->json('room.amenities'), 'code');
        $this->assertEqualsCanonicalizing(['wifi', 'balcony'], $codes);
    }

    public function test_amenity_added_from_a_room_form_goes_to_the_central_list(): void
    {
        $roomType = $this->makeRoomType(['amenities' => ['wifi']], 1);
        $unit = $this->firstUnit($roomType);

        $code = $this->actingAs($this->owner)->postJson($this->api('/amenities'), ['name' => 'Private Plunge Pool', 'category' => 'room'])
            ->assertCreated()->json('amenity.id');
        $this->actingAs($this->owner)->putJson($this->api('/rooms/'.$unit->public_id), ['amenities' => ['wifi', $code]])->assertOk();

        $this->assertSame(1, Amenity::query()->where('code', $code)->count());
        // The same amenity is offered on every form of the property and cannot be added twice.
        $this->actingAs($this->owner)->get($this->page('/rooms'))->assertOk()->assertSee($code, false);
        $this->actingAs($this->owner)->postJson($this->api('/amenities'), ['name' => 'private plunge pool', 'category' => 'room'])->assertUnprocessable();
    }

    public function test_unknown_or_foreign_amenities_are_rejected(): void
    {
        $unit = $this->firstUnit($this->makeRoomType([], 1));
        $foreign = Amenity::query()->create([
            'property_id' => $this->other->id, 'code' => 'c_other_spa', 'name' => 'Other Spa', 'category' => 'room',
            'applies_to' => 'room_type,unit', 'is_active' => true,
        ]);

        $this->actingAs($this->owner)->putJson($this->api('/rooms/'.$unit->public_id), ['amenities' => ['does_not_exist']])
            ->assertUnprocessable()->assertJsonPath('error.fields.amenities.0', __('rooms.errors.amenity_unknown'));
        $this->actingAs($this->owner)->putJson($this->api('/rooms/'.$unit->public_id), ['amenities' => [$foreign->code]])->assertUnprocessable();
    }

    public function test_property_facilities_of_the_room_type_are_not_removed_from_rooms(): void
    {
        $roomType = $this->makeRoomType(['amenities' => ['wifi', 'swimming_pool']], 1);
        $unit = $this->firstUnit($roomType);

        $this->actingAs($this->owner)->putJson($this->api('/rooms/'.$unit->public_id), ['amenities' => ['wifi']])->assertOk();
        $this->assertSame(0, DB::table('physical_unit_amenities')->where('unit_id', $unit->id)->count());

        $this->actingAs($this->owner)->putJson($this->api('/rooms/'.$unit->public_id), ['amenities' => ['wifi', 'parking']])->assertUnprocessable();
    }
}
