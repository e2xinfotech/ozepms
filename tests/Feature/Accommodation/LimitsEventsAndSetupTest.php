<?php

namespace Tests\Feature\Accommodation;

use App\Domain\Accommodation\Events\ProductChanged;
use App\Domain\Accommodation\Events\RoomTypeUnitsChanged;
use App\Domain\Accommodation\Events\UnitBlockChanged;
use App\Models\PhysicalUnit;
use App\Models\Product;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\UnitBlock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Subscription limits for room types and PMS rooms, the events the inventory module listens
 * to, and the room type form's "No Rate Plan Found → + Create Rate Plan" set-up flow.
 */
class LimitsEventsAndSetupTest extends AccommodationTestCase
{
    private function plan(): SubscriptionPlan
    {
        return SubscriptionPlan::query()->findOrFail(Subscription::query()->where('property_id', $this->property->id)->value('plan_id'));
    }

    private function roomTypePayload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'STD', 'name' => 'Standard Room', 'base_adults' => 2, 'max_adults' => 2, 'max_children' => 1,
            'max_infants' => 1, 'max_occupancy' => 3, 'quantity' => 1,
        ], $overrides);
    }

    public function test_room_type_limit_of_the_plan_is_enforced(): void
    {
        $this->makeRoomType(['code' => 'ONE'], 1);
        $this->plan()->forceFill(['max_room_types' => 1])->save();

        $this->actingAs($this->owner)->postJson($this->api('/room-types'), $this->roomTypePayload())
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['name']]]);
        $this->assertSame(1, RoomType::acrossProperties()->where('property_id', $this->property->id)->count());

        $this->plan()->forceFill(['max_room_types' => null])->save();
        $this->actingAs($this->owner)->postJson($this->api('/room-types'), $this->roomTypePayload())->assertCreated();
    }

    public function test_unit_limit_applies_to_bulk_creation_and_growing_a_room_type(): void
    {
        $roomType = $this->makeRoomType(['code' => 'DLX'], 2);
        $this->plan()->forceFill(['max_units' => 3])->save();

        $this->actingAs($this->owner)->postJson($this->api('/rooms/bulk'), [
            'room_type_id' => $roomType->public_id, 'mode' => 'range', 'range' => '301-302',
        ])->assertStatus(422);
        $this->actingAs($this->owner)->putJson($this->api('/room-types/'.$roomType->public_id), ['quantity' => 4])->assertStatus(422);
        $this->actingAs($this->owner)->putJson($this->api('/room-types/'.$roomType->public_id), ['quantity' => 3])->assertOk();

        $this->assertSame(3, PhysicalUnit::acrossProperties()->where('property_id', $this->property->id)->where('is_active', true)->count());
    }

    public function test_room_blocks_tell_the_inventory_module_which_dates_changed(): void
    {
        $unit = $this->firstUnit($this->makeRoomType([], 1));
        $from = date('Y-m-d', strtotime($this->today().' +2 days'));
        $to = date('Y-m-d', strtotime($from.' +3 days'));
        Event::fake([UnitBlockChanged::class]);

        $res = $this->actingAs($this->owner)->postJson($this->api('/rooms/'.$unit->public_id.'/blocks'), [
            'block_type' => 'out_of_order', 'start_date' => $from, 'end_date' => $to, 'reason' => 'Leak',
        ])->assertSuccessful();
        Event::assertDispatched(UnitBlockChanged::class, fn ($e) => $e->roomTypeId === (int) $unit->room_type_id
            && $e->from->toDateString() === $from && $e->to->toDateString() === $to);

        $blockId = UnitBlock::acrossProperties()->where('unit_id', $unit->id)->value('id');
        $this->actingAs($this->owner)->deleteJson($this->api('/rooms/'.$unit->public_id.'/blocks/'.$blockId))->assertOk();
        Event::assertDispatchedTimes(UnitBlockChanged::class, 2);
        $this->assertNotNull($res);
    }

    public function test_room_type_and_mapping_changes_dispatch_their_events(): void
    {
        Event::fake([RoomTypeUnitsChanged::class, ProductChanged::class]);

        $this->actingAs($this->owner)->postJson($this->api('/room-types'), $this->roomTypePayload([
            'quantity' => 2,
            'products' => [['rate_plan_id' => $this->bar()->public_id, 'enabled' => true, 'pricing_mode' => 'manual', 'default_price' => '2500', 'is_default' => true]],
        ]))->assertCreated();

        Event::assertDispatched(RoomTypeUnitsChanged::class);
        Event::assertDispatched(ProductChanged::class);
        $roomType = RoomType::acrossProperties()->where('property_id', $this->property->id)->where('code', 'STD')->firstOrFail();
        $product = Product::acrossProperties()->where('room_type_id', $roomType->id)->firstOrFail();
        $this->assertTrue((bool) $product->is_default);
        $this->assertSame('2500.00', (string) $product->default_price);
    }

    public function test_room_type_form_offers_rate_plan_creation_when_none_exists(): void
    {
        // A property without any rate plan (the default one was removed).
        DB::table('room_type_rate_plans')->where('property_id', $this->property->id)->delete();
        DB::table('rate_plans')->where('property_id', $this->property->id)->update(['deleted_at' => now()]);

        $page = $this->actingAs($this->owner)->get($this->page('/room-types/new?onboarding=1'))->assertOk();
        $page->assertSee('"rate_plans":[]', false)->assertSee('"create_rate_plan":true', false)->assertSee('"meal_plans":[{', false);
        $this->assertStringContainsString('"policies":[{', $page->getContent());

        // "+ Create Rate Plan" in the form posts the short form; the room type is then saved with the new plan linked.
        $created = $this->actingAs($this->owner)->postJson($this->api('/rate-plans'), [
            'code' => 'BAR2', 'name' => 'Room Only', 'meal_plan' => 'RO', 'cancellation_policy' => 'FLEX', 'is_active' => true, 'is_default' => true,
        ])->assertCreated()->json('rate_plan');
        $this->assertTrue($created['is_default']);

        $this->actingAs($this->owner)->postJson($this->api('/room-types'), $this->roomTypePayload([
            'quantity' => 3,
            'products' => [['rate_plan_id' => $created['id'], 'enabled' => true, 'pricing_mode' => 'manual', 'default_price' => '3000', 'is_default' => true]],
        ]))->assertCreated();

        $property = Property::query()->findOrFail($this->property->id);
        $this->assertSame(1, Product::acrossProperties()->where('property_id', $property->id)->where('is_active', true)->count());
    }

    public function test_rate_plan_creation_from_the_form_follows_permissions(): void
    {
        $manager = $this->member('hotel_manager');
        $this->actingAs($manager)->get($this->page('/room-types/new'))->assertOk()->assertSee('"create_rate_plan":true', false);

        $frontDesk = $this->member('front_desk');
        $this->actingAs($frontDesk)->postJson($this->api('/rate-plans'), [
            'code' => 'X1', 'name' => 'X', 'meal_plan' => 'RO', 'cancellation_policy' => 'FLEX',
        ])->assertForbidden();
        $this->assertFalse(RatePlan::acrossProperties()->where('property_id', $this->property->id)->where('code', 'X1')->exists());
    }
}
