<?php

namespace Tests\Feature\Accommodation;

use App\Domain\Accommodation\Events\ProductChanged;
use App\Models\CancellationPolicy;
use App\Models\Product;
use App\Models\RatePlan;
use Illuminate\Support\Facades\Event;

class RatePlansTest extends AccommodationTestCase
{
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'bb',
            'name' => 'Breakfast Included',
            'description' => 'Daily breakfast for all guests.',
            'meal_plan' => 'BB',
            'cancellation_policy' => 'FLEX',
            'payment_type' => 'pay_at_property',
            'default_min_los' => 1,
            'sell_on_pms' => true,
            'sell_on_booking_engine' => true,
            'sell_on_channels' => false,
        ], $overrides);
    }

    public function test_list_page_renders_with_kpis(): void
    {
        $this->actingAs($this->owner)->get($this->page('/rate-plans'))->assertOk()
            ->assertSee($this->pageName('property/rate-plans/index'), false)
            ->assertSee('Standard Rate', false)
            ->assertSee('"mapped":0', false);
    }

    public function test_pages_require_permission(): void
    {
        $plan = $this->bar();
        $this->actingAs($this->member('housekeeping'))->get($this->page('/rate-plans'))->assertForbidden();
        $frontDesk = $this->member('front_desk');
        $this->actingAs($frontDesk)->get($this->page('/rate-plans'))->assertOk();
        $this->actingAs($frontDesk)->get($this->page('/rate-plans/new'))->assertForbidden();
        $this->actingAs($frontDesk)->get($this->page('/rate-plans/'.$plan->public_id.'/edit'))->assertForbidden();
        $this->actingAs($this->owner)->get($this->page('/rate-plans/new'))->assertOk()->assertSee($this->pageName('property/rate-plans/form'), false);
        $this->actingAs($this->owner)->get($this->page('/rate-plans/'.$plan->public_id.'/edit'))->assertOk();
    }

    public function test_store_creates_plan_with_manual_prices_and_occupancy_rules(): void
    {
        Event::fake([ProductChanged::class]);
        $roomType = $this->makeRoomType(['code' => 'DLX'], 1);

        $response = $this->actingAs($this->owner)->postJson($this->api('/rate-plans'), $this->payload([
            'room_types' => [[
                'room_type_id' => $roomType->public_id, 'enabled' => true, 'pricing_mode' => 'manual', 'default_price' => 5500,
                'occupancy_rules' => [
                    ['guest_type' => 'adult', 'guest_count' => 1, 'adjust_type' => 'fixed', 'adjust_value' => -500],
                    ['guest_type' => 'adult', 'guest_count' => 3, 'adjust_type' => 'fixed', 'adjust_value' => 1200],
                    ['guest_type' => 'child', 'guest_count' => 1, 'age_band' => 'child', 'adjust_type' => 'fixed', 'adjust_value' => 600],
                ],
            ]],
        ]))->assertCreated()
            ->assertJsonPath('rate_plan.code', 'BB')
            ->assertJsonPath('rate_plan.meal_plan.code', 'BB')
            ->assertJsonPath('rate_plan.base_rate', '5500.00')
            ->assertJsonPath('rate_plan.products.0.default_price', '5500.00');

        $this->assertCount(3, $response->json('rate_plan.products.0.occupancy_rules'));
        Event::assertDispatched(ProductChanged::class);
    }

    public function test_store_with_derived_prices_follows_parent(): void
    {
        $roomType = $this->makeRoomType([], 1);
        $this->actingAs($this->owner)->putJson($this->api('/rate-plans/'.$this->bar()->public_id), [
            'room_types' => [['room_type_id' => $roomType->public_id, 'enabled' => true, 'pricing_mode' => 'manual', 'default_price' => 4000]],
        ])->assertOk();

        $this->actingAs($this->owner)->postJson($this->api('/rate-plans'), $this->payload([
            'code' => 'NRF', 'name' => 'Non-Refundable', 'meal_plan' => 'RO', 'cancellation_policy' => 'NRF',
            'room_types' => [[
                'room_type_id' => $roomType->public_id, 'enabled' => true, 'pricing_mode' => 'derived',
                'parent_rate_plan_id' => $this->bar()->public_id, 'adjust_type' => 'percent', 'adjust_value' => -10,
            ]],
        ]))->assertCreated()
            ->assertJsonPath('rate_plan.products.0.pricing_mode', 'derived')
            ->assertJsonPath('rate_plan.products.0.base_price', '3600.00')
            ->assertJsonPath('rate_plan.policy.refundable', false);
    }

    public function test_derived_price_needs_a_linked_parent(): void
    {
        $roomType = $this->makeRoomType([], 1);

        $this->actingAs($this->owner)->postJson($this->api('/rate-plans'), $this->payload([
            'room_types' => [[
                'room_type_id' => $roomType->public_id, 'enabled' => true, 'pricing_mode' => 'derived',
                'parent_rate_plan_id' => $this->bar()->public_id, 'adjust_type' => 'percent', 'adjust_value' => -10,
            ]],
        ]))->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['room_types.0.parent_rate_plan_id']]]);
        $this->assertFalse(RatePlan::acrossProperties()->where('property_id', $this->property->id)->where('code', 'BB')->exists());
    }

    public function test_store_validates_input(): void
    {
        $this->actingAs($this->owner)->postJson($this->api('/rate-plans'), $this->payload(['code' => 'BAR', 'meal_plan' => 'XX']))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['code']]]);
        $this->actingAs($this->owner)->postJson($this->api('/rate-plans'), $this->payload(['meal_plan' => 'XX']))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['meal_plan']]]);
        $this->actingAs($this->owner)->postJson($this->api('/rate-plans'), $this->payload(['default_min_los' => 3, 'default_max_los' => 2]))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['default_max_los']]]);
        $this->actingAs($this->owner)->postJson($this->api('/rate-plans'), $this->payload(['room_types' => [['room_type_id' => 'nope', 'enabled' => true, 'default_price' => 1]]]))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['room_types.0.room_type_id']]]);
    }

    public function test_store_is_forbidden_without_permission(): void
    {
        $this->actingAs($this->member('front_desk'))->postJson($this->api('/rate-plans'), $this->payload())->assertForbidden();
    }

    public function test_records_of_another_property_are_not_found(): void
    {
        $foreign = $this->bar($this->other);

        $this->actingAs($this->owner)->getJson($this->api('/rate-plans/'.$foreign->public_id))->assertNotFound();
        $this->actingAs($this->owner)->putJson($this->api('/rate-plans/'.$foreign->public_id), ['name' => 'X'])->assertNotFound();
        $this->actingAs($this->owner)->postJson($this->api('/rate-plans/'.$foreign->public_id.'/status'), ['is_active' => false])->assertNotFound();
        $this->actingAs($this->owner)->postJson($this->api('/rate-plans/'.$foreign->public_id.'/copy'))->assertNotFound();
        $this->actingAs($this->owner)->get($this->page('/rate-plans/'.$foreign->public_id.'/edit'))->assertNotFound();
    }

    public function test_show_returns_panel_data(): void
    {
        $this->actingAs($this->owner)->getJson($this->api('/rate-plans/'.$this->bar()->public_id))->assertOk()
            ->assertJsonPath('rate_plan.code', 'BAR')
            ->assertJsonPath('rate_plan.is_default', true)
            ->assertJsonPath('rate_plan.cancellation_policy.code', 'FLEX')
            ->assertJsonStructure(['rate_plan' => ['history', 'products', 'channels']]);
    }

    public function test_default_plan_cannot_be_deactivated_but_others_can(): void
    {
        $this->actingAs($this->owner)->postJson($this->api('/rate-plans/'.$this->bar()->public_id.'/status'), ['is_active' => false])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['is_active']]]);

        $id = $this->actingAs($this->owner)->postJson($this->api('/rate-plans'), $this->payload())->assertCreated()->json('rate_plan.id');
        $this->actingAs($this->owner)->postJson($this->api('/rate-plans/'.$id.'/status'), ['is_active' => false])
            ->assertOk()->assertJsonPath('is_active', false);
    }

    public function test_parent_plan_cannot_be_deactivated_while_children_follow_it(): void
    {
        $roomType = $this->makeRoomType([], 1);
        $bb = $this->actingAs($this->owner)->postJson($this->api('/rate-plans'), $this->payload([
            'room_types' => [['room_type_id' => $roomType->public_id, 'enabled' => true, 'pricing_mode' => 'manual', 'default_price' => 3000]],
        ]))->json('rate_plan.id');
        $this->actingAs($this->owner)->postJson($this->api('/rate-plans'), $this->payload([
            'code' => 'BBNR', 'room_types' => [['room_type_id' => $roomType->public_id, 'enabled' => true, 'pricing_mode' => 'derived', 'parent_rate_plan_id' => $bb, 'adjust_type' => 'fixed', 'adjust_value' => -200]],
        ]))->assertCreated();

        $this->actingAs($this->owner)->postJson($this->api('/rate-plans/'.$bb.'/status'), ['is_active' => false])->assertStatus(422);
    }

    public function test_update_and_copy(): void
    {
        $roomType = $this->makeRoomType([], 1);
        $id = $this->actingAs($this->owner)->postJson($this->api('/rate-plans'), $this->payload([
            'room_types' => [['room_type_id' => $roomType->public_id, 'enabled' => true, 'pricing_mode' => 'manual', 'default_price' => 3000]],
        ]))->json('rate_plan.id');

        $this->actingAs($this->owner)->putJson($this->api('/rate-plans/'.$id), ['name' => 'Bed & Breakfast', 'default_min_los' => 2])
            ->assertOk()->assertJsonPath('rate_plan.name', 'Bed & Breakfast')->assertJsonPath('rate_plan.min_los', 2);

        $this->actingAs($this->owner)->postJson($this->api('/rate-plans/'.$id.'/copy'))->assertCreated()
            ->assertJsonPath('rate_plan.code', 'BB-C')->assertJsonPath('rate_plan.is_active', false)
            ->assertJsonPath('rate_plan.products.0.default_price', '3000.00');
    }

    public function test_unlinking_a_room_type_deactivates_the_product(): void
    {
        $roomType = $this->makeRoomType([], 1);
        $id = $this->actingAs($this->owner)->postJson($this->api('/rate-plans'), $this->payload([
            'room_types' => [['room_type_id' => $roomType->public_id, 'enabled' => true, 'pricing_mode' => 'manual', 'default_price' => 3000]],
        ]))->json('rate_plan.id');

        $this->actingAs($this->owner)->putJson($this->api('/rate-plans/'.$id), ['room_types' => [['room_type_id' => $roomType->public_id, 'enabled' => false]]])->assertOk();
        $this->assertFalse(Product::acrossProperties()->where('room_type_id', $roomType->id)->firstOrFail()->is_active);
    }

    public function test_cancellation_policy_can_be_created_and_updated(): void
    {
        $this->actingAs($this->owner)->postJson($this->api('/cancellation-policies'), [
            'code' => 'flex48', 'name' => 'Free cancellation until 48 h', 'is_refundable' => true,
            'rules' => [['applies_to' => 'cancellation', 'hours_before_arrival' => 48, 'charge_type' => 'first_night']],
        ])->assertCreated()->assertJsonPath('policy', 'FLEX48');

        $this->actingAs($this->owner)->putJson($this->api('/cancellation-policies/FLEX48'), [
            'name' => 'Free cancellation until 72 h',
            'rules' => [['applies_to' => 'cancellation', 'hours_before_arrival' => 72, 'charge_type' => 'percent', 'charge_value' => 50]],
        ])->assertOk();
        $this->assertSame(72, CancellationPolicy::acrossProperties()->where('property_id', $this->property->id)->where('code', 'FLEX48')->firstOrFail()->rules()->value('hours_before_arrival'));

        $this->actingAs($this->owner)->postJson($this->api('/cancellation-policies'), [
            'code' => 'nr2', 'name' => 'Bad', 'is_refundable' => false,
            'rules' => [['applies_to' => 'cancellation', 'hours_before_arrival' => 24, 'charge_type' => 'none']],
        ])->assertStatus(422);
        $this->actingAs($this->owner)->putJson('/web-api/p/'.$this->property->code.'/cancellation-policies/NOPE', ['name' => 'x'])->assertNotFound();
        $this->actingAs($this->member('front_desk'))->postJson($this->api('/cancellation-policies'), ['code' => 'x'])->assertForbidden();
    }
}
