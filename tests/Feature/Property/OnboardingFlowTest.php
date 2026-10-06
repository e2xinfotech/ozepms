<?php

namespace Tests\Feature\Property;

use App\Domain\Property\OnboardingService;
use App\Models\AuditLog;
use App\Models\Property;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Accommodation\AccommodationTestCase;

/** First login: create property → rate plan → room types with PMS rooms → property becomes active. */
class OnboardingFlowTest extends AccommodationTestCase
{
    private function fields(): array
    {
        return ['name' => 'Seaside Homestay', 'property_type_id' => 1, 'country_iso2' => 'IN', 'currency_code' => 'INR',
            'timezone' => 'Asia/Kolkata', 'default_language' => 'en'];
    }

    public function test_user_without_property_is_sent_to_create_one(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->get('/home')->assertRedirect(route('properties.create'));
        $this->actingAs($user)->get('/properties/new')->assertOk();
    }

    public function test_after_creating_the_property_the_next_step_opens(): void
    {
        $user = $this->makeUser();

        $res = $this->actingAs($user)->postJson('/web-api/properties', $this->fields())->assertCreated();
        $property = Property::query()->where('code', $res->json('code'))->firstOrFail();

        $this->assertSame('onboarding', $property->status);
        // A default rate plan is created with the property, so the next step is the room types.
        $this->assertGreaterThan(0, DB::table('rate_plans')->where('property_id', $property->id)->count());
        $this->assertStringContainsString("/p/{$property->code}/room-types/new?onboarding=1", $res->json('redirect'));

        $steps = collect(app(OnboardingService::class)->steps($property))->keyBy('key');
        $this->assertTrue($steps['step_rate_plan']['done']);
        $this->assertFalse($steps['step_room_types']['done']);
        $this->assertTrue(app(OnboardingService::class)->pending($property));
    }

    public function test_without_a_rate_plan_the_rate_plan_step_comes_first(): void
    {
        $user = $this->makeUser();
        $res = $this->actingAs($user)->postJson('/web-api/properties', $this->fields())->assertCreated();
        $property = Property::query()->where('code', $res->json('code'))->firstOrFail();
        DB::table('room_type_rate_plans')->where('property_id', $property->id)->delete();
        DB::table('rate_plans')->where('property_id', $property->id)->update(['deleted_at' => now()]);

        $this->assertStringContainsString("/p/{$property->code}/rate-plans/new?onboarding=1", app(OnboardingService::class)->nextUrl($property));
        app(OnboardingService::class)->sync($property);
        $this->assertSame('rate_plan', $property->refresh()->onboarding_step);
    }

    public function test_property_becomes_active_when_rooms_exist(): void
    {
        $user = $this->makeUser();
        $res = $this->actingAs($user)->postJson('/web-api/properties', $this->fields())->assertCreated();
        $property = Property::query()->where('code', $res->json('code'))->firstOrFail();

        $page = $this->actingAs($user)->get("/p/{$property->code}/dashboard")->assertOk();
        $page->assertSee('room-types\/new?onboarding=1', false);

        $this->makeRoomType([], 2, $property);

        $property->refresh();
        $this->assertSame('active', $property->status);
        $this->assertSame('done', $property->onboarding_step);
        $this->assertTrue(AuditLog::query()->where('action', 'property.onboarding_completed')->where('property_id', $property->id)->exists());
        $this->assertNull(app(OnboardingService::class)->nextUrl($property));
    }

    public function test_suspended_properties_are_not_activated_by_setup(): void
    {
        $property = $this->property;
        $property->forceFill(['status' => 'suspended'])->save();
        $this->makeRoomType([], 1);

        app(OnboardingService::class)->sync($property);
        $this->assertSame('suspended', $property->refresh()->status);
    }
}
