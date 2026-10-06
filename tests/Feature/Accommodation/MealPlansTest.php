<?php

namespace Tests\Feature\Accommodation;

use App\Models\MealPlan;
use App\Models\RatePlan;

/** Standard meal plans plus a property's own ("Custom") ones, used by rate plans. */
class MealPlansTest extends AccommodationTestCase
{
    public function test_standard_meal_plans_cover_the_spec_list(): void
    {
        $codes = MealPlan::query()->whereNull('property_id')->pluck('code')->all();
        foreach (['RO', 'BB', 'LO', 'DI', 'BD', 'HB', 'FB', 'AI'] as $code) {
            $this->assertContains($code, $codes);
        }
    }

    public function test_custom_meal_plan_can_be_added_and_used_by_a_rate_plan(): void
    {
        $this->actingAs($this->owner)->postJson($this->api('/meal-plans'), [
            'code' => 'bspa', 'name' => 'Breakfast and Spa', 'includes_breakfast' => true,
        ])->assertCreated()->assertJsonPath('meal_plan', 'BSPA')
            ->assertJsonFragment(['value' => 'BSPA']);

        $this->actingAs($this->owner)->postJson($this->api('/rate-plans'), [
            'code' => 'SPA', 'name' => 'Spa Package', 'meal_plan' => 'BSPA', 'cancellation_policy' => 'FLEX',
        ])->assertCreated()->assertJsonPath('rate_plan.meal_plan.code', 'BSPA');

        $plan = MealPlan::query()->where('code', 'BSPA')->firstOrFail();
        $this->assertSame($this->property->id, (int) $plan->property_id);
        $this->assertTrue($plan->includes_breakfast);
        $this->assertFalse($plan->includes_dinner);
    }

    public function test_codes_and_names_are_not_duplicated(): void
    {
        $this->actingAs($this->owner)->postJson($this->api('/meal-plans'), ['code' => 'BB', 'name' => 'My breakfast'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['code']]]);
        $this->actingAs($this->owner)->postJson($this->api('/meal-plans'), ['code' => 'X1', 'name' => 'Lunch box'])->assertCreated();
        $this->actingAs($this->owner)->postJson($this->api('/meal-plans'), ['code' => 'X2', 'name' => 'Lunch box'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['name']]]);
        $this->actingAs($this->owner)->postJson($this->api('/meal-plans'), ['code' => 'bad code!', 'name' => ''])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['code', 'name']]]);
    }

    public function test_custom_meal_plans_are_private_to_their_property(): void
    {
        $this->actingAs($this->owner)->postJson($this->api('/meal-plans'), ['code' => 'PRIV', 'name' => 'Private board'])->assertCreated();

        // The other property can use the same code and cannot pick the first property's plan.
        $other = "/web-api/p/{$this->other->code}";
        $this->actingAs($this->otherOwner)->postJson("$other/rate-plans", [
            'code' => 'P1', 'name' => 'P1', 'meal_plan' => 'PRIV', 'cancellation_policy' => 'FLEX',
        ])->assertStatus(422);
        $this->actingAs($this->otherOwner)->postJson("$other/meal-plans", ['code' => 'PRIV', 'name' => 'Private board'])->assertCreated();
        $this->assertFalse(RatePlan::acrossProperties()->where('property_id', $this->other->id)->where('code', 'P1')->exists());
    }

    public function test_adding_meal_plans_needs_permission(): void
    {
        $this->actingAs($this->member('front_desk'))->postJson($this->api('/meal-plans'), ['code' => 'FD', 'name' => 'FD'])->assertForbidden();
        $this->assertFalse(MealPlan::query()->where('code', 'FD')->exists());
    }
}
