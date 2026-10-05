<?php

namespace Tests\Feature\Accommodation;

use App\Domain\Accommodation\Events\RoomTypeUnitsChanged;
use App\Models\PhysicalUnit;
use App\Models\Product;
use App\Models\RoomType;
use App\Models\RoomTypeImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

class RoomTypesTest extends AccommodationTestCase
{
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'dlx',
            'name' => 'Deluxe King',
            'category' => 'deluxe',
            'description' => 'Spacious room with a king bed.',
            'base_adults' => 2,
            'max_adults' => 3,
            'max_children' => 1,
            'max_infants' => 1,
            'max_occupancy' => 3,
            'beds' => [['bed_type' => 'king', 'quantity' => 1]],
            'amenities' => ['wifi', 'air_conditioning'],
            'quantity' => 3,
        ], $overrides);
    }

    public function test_list_page_renders_for_owner(): void
    {
        $this->makeRoomType(['name' => 'Garden Suite']);

        $this->actingAs($this->owner)->get($this->page('/room-types'))
            ->assertOk()->assertSee($this->pageName('property/room-types/index'), false)->assertSee('Garden Suite', false);
    }

    public function test_list_filters_by_status_and_search(): void
    {
        $this->makeRoomType(['name' => 'Alpha Room', 'code' => 'ALP']);
        $hidden = $this->makeRoomType(['name' => 'Beta Room', 'code' => 'BET']);
        $this->actingAs($this->owner)->postJson($this->api('/room-types/'.$hidden->public_id.'/status'), ['is_active' => false])->assertOk();

        $this->actingAs($this->owner)->get($this->page('/room-types?status=active&q=Room'))
            ->assertOk()->assertSee('Alpha Room', false)->assertDontSee('Beta Room', false);
    }

    public function test_pages_require_permission(): void
    {
        $guestRelations = $this->member('guest_relations');
        $housekeeping = $this->member('housekeeping');
        $roomType = $this->makeRoomType();

        $this->actingAs($guestRelations)->get($this->page('/room-types'))->assertForbidden();
        $this->actingAs($housekeeping)->get($this->page('/room-types'))->assertOk();
        $this->actingAs($housekeeping)->get($this->page('/room-types/new'))->assertForbidden();
        $this->actingAs($housekeeping)->get($this->page('/room-types/'.$roomType->public_id.'/edit'))->assertForbidden();
    }

    public function test_create_and_edit_pages_render(): void
    {
        $roomType = $this->makeRoomType(['name' => 'Ocean Suite']);

        $this->actingAs($this->owner)->get($this->page('/room-types/new'))->assertOk()->assertSee($this->pageName('property/room-types/form'), false);
        $this->actingAs($this->owner)->get($this->page('/room-types/'.$roomType->public_id.'/edit'))->assertOk()->assertSee('Ocean Suite', false);
    }

    public function test_edit_page_of_another_property_is_not_found(): void
    {
        $foreign = $this->makeRoomType([], 1, $this->other);

        $this->actingAs($this->owner)->get($this->page('/room-types/'.$foreign->public_id.'/edit'))->assertNotFound();
    }

    public function test_store_creates_room_type_with_pms_rooms_from_quantity(): void
    {
        Event::fake([RoomTypeUnitsChanged::class]);

        $response = $this->actingAs($this->owner)->postJson($this->api('/room-types'), $this->payload())
            ->assertCreated()
            ->assertJsonPath('room_type.code', 'DLX')
            ->assertJsonPath('room_type.active_units', 3)
            ->assertJsonPath('room_type.beds.0.bed_type', 'king');

        $this->assertSame(['DLX-01', 'DLX-02', 'DLX-03'], collect($response->json('room_type.units'))->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['wifi', 'air_conditioning'], $response->json('room_type.amenities'));
        Event::assertDispatched(RoomTypeUnitsChanged::class, fn ($e) => $e->propertyId === $this->property->id);
    }

    public function test_store_links_rate_plans_with_manual_and_derived_prices(): void
    {
        $bar = $this->bar();
        $this->inProperty($this->property);
        $nrf = app(\App\Domain\Rates\RatePlanService::class)->create([
            'code' => 'NRF', 'name' => 'Non-Refundable',
            'meal_plan' => \App\Models\MealPlan::query()->whereNull('property_id')->where('code', 'RO')->first(),
            'cancellation_policy' => \App\Models\CancellationPolicy::query()->where('code', 'NRF')->first(),
        ]);
        app(\App\Support\PropertyContext::class)->clear();

        $this->actingAs($this->owner)->postJson($this->api('/room-types'), $this->payload([
            'products' => [
                ['rate_plan_id' => $bar->public_id, 'enabled' => true, 'pricing_mode' => 'manual', 'default_price' => 5000],
                ['rate_plan_id' => $nrf->public_id, 'enabled' => true, 'pricing_mode' => 'derived', 'parent_rate_plan_id' => $bar->public_id, 'adjust_type' => 'percent', 'adjust_value' => -10],
            ],
        ]))->assertCreated()
            ->assertJsonPath('room_type.products.0.base_price', '5000.00')
            ->assertJsonPath('room_type.products.1.pricing_mode', 'derived')
            ->assertJsonPath('room_type.products.1.base_price', '4500.00');
    }

    public function test_store_validates_input(): void
    {
        $this->actingAs($this->owner)->postJson($this->api('/room-types'), $this->payload(['name' => '', 'base_adults' => 4, 'max_adults' => 2]))
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['fields' => ['name']]]);

        $this->actingAs($this->owner)->postJson($this->api('/room-types'), $this->payload(['base_adults' => 4, 'max_adults' => 2, 'max_occupancy' => 4]))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['base_adults']]]);
    }

    public function test_store_rejects_duplicate_code_in_same_property_only(): void
    {
        $this->makeRoomType(['code' => 'DLX']);
        $this->makeRoomType(['code' => 'STE'], 1, $this->other);

        $this->actingAs($this->owner)->postJson($this->api('/room-types'), $this->payload(['code' => 'DLX']))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['code']]]);
        $this->actingAs($this->owner)->postJson($this->api('/room-types'), $this->payload(['code' => 'STE']))->assertCreated();
    }

    public function test_store_is_forbidden_without_permission(): void
    {
        $frontDesk = $this->member('front_desk');

        $this->actingAs($frontDesk)->postJson($this->api('/room-types'), $this->payload())->assertForbidden();
        $this->assertSame(0, RoomType::acrossProperties()->where('property_id', $this->property->id)->count());
    }

    public function test_show_update_and_status_of_another_property_are_not_found(): void
    {
        $foreign = $this->makeRoomType([], 1, $this->other);

        $this->actingAs($this->owner)->getJson($this->api('/room-types/'.$foreign->public_id))->assertNotFound();
        $this->actingAs($this->owner)->putJson($this->api('/room-types/'.$foreign->public_id), ['name' => 'Hacked'])->assertNotFound();
        $this->actingAs($this->owner)->postJson($this->api('/room-types/'.$foreign->public_id.'/status'), ['is_active' => false])->assertNotFound();
        $this->assertSame($foreign->name, $foreign->fresh()->name);
    }

    public function test_update_changes_fields_and_grows_inventory(): void
    {
        $roomType = $this->makeRoomType(['code' => 'SUP'], 2);

        $this->actingAs($this->owner)->putJson($this->api('/room-types/'.$roomType->public_id), ['name' => 'Superior Twin', 'quantity' => 4])
            ->assertOk()->assertJsonPath('room_type.name', 'Superior Twin')->assertJsonPath('room_type.active_units', 4);

        $this->actingAs($this->owner)->putJson($this->api('/room-types/'.$roomType->public_id), ['quantity' => 1])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['quantity']]]);
    }

    public function test_status_toggles_room_type(): void
    {
        $roomType = $this->makeRoomType();

        $this->actingAs($this->owner)->postJson($this->api('/room-types/'.$roomType->public_id.'/status'), ['is_active' => false])
            ->assertOk()->assertJsonPath('is_active', false);
        $this->assertFalse($roomType->fresh()->is_active);
        $this->actingAs($this->owner)->postJson($this->api('/room-types/'.$roomType->public_id.'/status'), ['is_active' => 'maybe'])->assertStatus(422);
    }

    public function test_default_rate_plan_can_be_changed(): void
    {
        $roomType = $this->makeRoomType();
        $this->actingAs($this->owner)->putJson($this->api('/room-types/'.$roomType->public_id), [
            'products' => [['rate_plan_id' => $this->bar()->public_id, 'enabled' => true, 'pricing_mode' => 'manual', 'default_price' => 3000]],
        ])->assertOk();
        $product = Product::acrossProperties()->where('room_type_id', $roomType->id)->firstOrFail();

        $this->actingAs($this->owner)->putJson($this->api('/room-types/'.$roomType->public_id.'/default-rate-plan'), ['product_id' => $product->public_id])->assertOk();
        $this->assertTrue($product->fresh()->is_default);

        $this->actingAs($this->owner)->putJson($this->api('/room-types/'.$roomType->public_id.'/default-rate-plan'), ['product_id' => 'missing'])
            ->assertStatus(422);
    }

    public function test_images_can_be_uploaded_and_removed(): void
    {
        Storage::fake('public');
        $roomType = $this->makeRoomType();

        $response = $this->actingAs($this->owner)->post($this->api('/room-types/'.$roomType->public_id.'/images'), [
            'image' => UploadedFile::fake()->image('room.jpg', 800, 600),
        ], ['Accept' => 'application/json'])->assertCreated();
        $imageId = $response->json('image.id');
        $path = RoomTypeImage::acrossProperties()->findOrFail($imageId)->path;
        Storage::disk('public')->assertExists($path);

        $this->actingAs($this->owner)->post($this->api('/room-types/'.$roomType->public_id.'/images'), [
            'image' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->actingAs($this->owner)->deleteJson($this->api('/room-types/'.$roomType->public_id.'/images/'.$imageId))->assertOk();
        Storage::disk('public')->assertMissing($path);
    }

    public function test_quantity_respects_plan_room_limit(): void
    {
        $plan = \App\Models\SubscriptionPlan::query()->findOrFail(\App\Models\Subscription::query()->where('property_id', $this->property->id)->value('plan_id'));
        $plan->forceFill(['max_units' => 3])->save();

        $this->actingAs($this->owner)->postJson($this->api('/room-types'), $this->payload(['quantity' => 4]))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['units']]]);
        $this->assertSame(0, PhysicalUnit::acrossProperties()->where('property_id', $this->property->id)->count());
    }
}
