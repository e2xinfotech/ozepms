<?php

namespace Tests\Feature\Accommodation;

use App\Domain\Accommodation\Events\ProductChanged;
use App\Domain\Rates\DerivedPricingGuard;
use App\Domain\Rates\ProductService;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\RoomType;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/**
 * Derived pricing: maths through the real endpoints, chains of derived plans, and the
 * guards against self-reference, cycles and chains that are too long.
 */
class PricingRulesTest extends AccommodationTestCase
{
    /** Creates a rate plan through the endpoint and links it to the room type. */
    private function plan(string $code, RoomType $roomType, array $link): RatePlan
    {
        $this->actingAs($this->owner)->postJson($this->api('/rate-plans'), [
            'code' => $code, 'name' => "Plan {$code}", 'meal_plan' => 'RO', 'cancellation_policy' => 'FLEX',
            'room_types' => [['room_type_id' => $roomType->public_id, 'enabled' => true] + $link],
        ])->assertCreated();

        return RatePlan::acrossProperties()->where('property_id', $this->property->id)->where('code', $code)->firstOrFail();
    }

    private function product(RoomType $roomType, RatePlan $plan): Product
    {
        return Product::acrossProperties()->where('room_type_id', $roomType->id)->where('rate_plan_id', $plan->id)->firstOrFail();
    }

    private function linkBar(RoomType $roomType, string $price): void
    {
        $this->actingAs($this->owner)->putJson($this->api('/rate-plans/'.$this->bar()->public_id), [
            'room_types' => [['room_type_id' => $roomType->public_id, 'enabled' => true, 'pricing_mode' => 'manual', 'default_price' => $price]],
        ])->assertOk();
    }

    public function test_derived_prices_follow_the_chain_of_parents(): void
    {
        $roomType = $this->makeRoomType(['base_adults' => 2], 1);
        $this->linkBar($roomType, '4000');

        $this->plan('NRF', $roomType, ['pricing_mode' => 'derived', 'parent_rate_plan_id' => $this->bar()->public_id, 'adjust_type' => 'percent', 'adjust_value' => '-10']);
        $nrf = RatePlan::acrossProperties()->where('property_id', $this->property->id)->where('code', 'NRF')->firstOrFail();
        // Per person: + 150 × base adults (2) on top of the NRF price.
        $this->plan('BBNRF', $roomType, ['pricing_mode' => 'derived', 'parent_rate_plan_id' => $nrf->public_id, 'adjust_type' => 'fixed_per_person', 'adjust_value' => '150']);
        $bbnrf = RatePlan::acrossProperties()->where('property_id', $this->property->id)->where('code', 'BBNRF')->firstOrFail();

        $this->actingAs($this->owner)->getJson($this->api('/rate-plans/'.$nrf->public_id))->assertOk()->assertJsonPath('rate_plan.products.0.base_price', '3600.00');
        $this->actingAs($this->owner)->getJson($this->api('/rate-plans/'.$bbnrf->public_id))->assertOk()->assertJsonPath('rate_plan.products.0.base_price', '3900.00');

        // A new parent price flows down to both derived plans and tells the rates module about all three.
        Event::fake([ProductChanged::class]);
        $this->linkBar($roomType, '5000');
        Event::assertDispatchedTimes(ProductChanged::class, 3);
        $this->actingAs($this->owner)->getJson($this->api('/rate-plans/'.$bbnrf->public_id))->assertJsonPath('rate_plan.products.0.base_price', '4800.00');
    }

    public function test_a_product_cannot_follow_itself_or_its_own_followers(): void
    {
        $roomType = $this->makeRoomType([], 1);
        $this->linkBar($roomType, '4000');
        $nrf = $this->plan('NRF', $roomType, ['pricing_mode' => 'derived', 'parent_rate_plan_id' => $this->bar()->public_id, 'adjust_type' => 'percent', 'adjust_value' => '-10']);

        // BAR → derived from NRF would close the loop BAR → NRF → BAR.
        $this->actingAs($this->owner)->putJson($this->api('/rate-plans/'.$this->bar()->public_id), [
            'room_types' => [['room_type_id' => $roomType->public_id, 'enabled' => true, 'pricing_mode' => 'derived',
                'parent_rate_plan_id' => $nrf->public_id, 'adjust_type' => 'percent', 'adjust_value' => '5']],
        ])->assertStatus(422)->assertJsonPath('error.fields', fn ($fields) => str_contains(json_encode($fields), __('rates.errors.parent_cycle')));

        // Same plan as its own parent.
        $this->actingAs($this->owner)->putJson($this->api('/rate-plans/'.$nrf->public_id), [
            'room_types' => [['room_type_id' => $roomType->public_id, 'enabled' => true, 'pricing_mode' => 'derived',
                'parent_rate_plan_id' => $nrf->public_id, 'adjust_type' => 'percent', 'adjust_value' => '5']],
        ])->assertStatus(422);

        $this->assertSame('manual', $this->product($roomType, $this->bar())->pricing_mode);
    }

    public function test_chains_of_derived_plans_are_limited(): void
    {
        $roomType = $this->makeRoomType([], 1);
        $this->linkBar($roomType, '4000');
        $parent = $this->bar();
        // BAR + 4 derived levels = 5 products in one chain (the maximum).
        for ($i = 1; $i < DerivedPricingGuard::MAX_DEPTH; $i++) {
            $parent = $this->plan("D{$i}", $roomType, ['pricing_mode' => 'derived', 'parent_rate_plan_id' => $parent->public_id, 'adjust_type' => 'fixed', 'adjust_value' => '-10']);
        }
        $this->actingAs($this->owner)->getJson($this->api('/rate-plans/'.$parent->public_id))->assertJsonPath('rate_plan.products.0.base_price', '3960.00');

        $this->actingAs($this->owner)->postJson($this->api('/rate-plans'), [
            'code' => 'DEEP', 'name' => 'Too deep', 'meal_plan' => 'RO', 'cancellation_policy' => 'FLEX',
            'room_types' => [['room_type_id' => $roomType->public_id, 'enabled' => true, 'pricing_mode' => 'derived',
                'parent_rate_plan_id' => $parent->public_id, 'adjust_type' => 'fixed', 'adjust_value' => '-10']],
        ])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['room_types.0.parent_rate_plan_id']]]);
    }

    public function test_parent_of_another_property_is_refused_by_the_guard(): void
    {
        $mine = $this->makeRoomType([], 1);
        $theirs = $this->makeRoomType([], 1, $this->other);
        $this->inProperty($this->other);
        $theirProduct = app(ProductService::class)->upsert($theirs, RatePlan::query()->where('code', 'BAR')->firstOrFail(), ['pricing_mode' => 'manual', 'default_price' => '100']);

        $this->inProperty($this->property);
        $this->expectException(ValidationException::class);
        app(ProductService::class)->upsert($mine, $this->bar(), [
            'pricing_mode' => 'derived', 'parent' => $theirProduct, 'adjust_type' => 'percent', 'adjust_value' => '-10',
        ]);
    }

    public function test_percent_discount_of_100_or_more_is_refused(): void
    {
        $roomType = $this->makeRoomType([], 1);
        $this->linkBar($roomType, '4000');

        $this->actingAs($this->owner)->postJson($this->api('/rate-plans'), [
            'code' => 'FREE', 'name' => 'Free', 'meal_plan' => 'RO', 'cancellation_policy' => 'FLEX',
            'room_types' => [['room_type_id' => $roomType->public_id, 'enabled' => true, 'pricing_mode' => 'derived',
                'parent_rate_plan_id' => $this->bar()->public_id, 'adjust_type' => 'percent', 'adjust_value' => '-100']],
        ])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['room_types.0.adjust_value']]]);
    }
}
