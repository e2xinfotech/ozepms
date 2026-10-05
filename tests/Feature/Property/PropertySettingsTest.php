<?php

namespace Tests\Feature\Property;

use App\Models\Property;
use Tests\TestCase;

class PropertySettingsTest extends TestCase
{
    private function fields(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Sunrise Grand Hotel', 'property_type_id' => 1, 'country_iso2' => 'AE', 'state_id' => null, 'city' => 'Dubai',
            'currency_code' => 'AED', 'timezone' => 'Asia/Dubai', 'default_language' => 'en', 'languages' => ['fr'],
            'check_in_time' => '15:00', 'check_out_time' => '12:00', 'star_rating' => 5, 'week_start' => 0,
        ], $overrides);
    }

    public function test_manager_updates_property_configuration(): void
    {
        [$property] = $this->createPropertyWithOwner();
        $manager = $this->addMember($property, 'hotel_manager');

        $this->actingAs($manager)->putJson("/web-api/p/{$property->code}/settings", $this->fields())
            ->assertOk()
            ->assertJsonPath('property.name', 'Sunrise Grand Hotel')
            ->assertJsonPath('property.currency_code', 'AED')
            ->assertJsonPath('property.check_in_time', '15:00')
            ->assertJsonPath('property.languages', ['fr']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'property.updated', 'property_id' => $property->id]);
    }

    public function test_settings_validation(): void
    {
        [$property, $owner] = $this->createPropertyWithOwner();

        $this->actingAs($owner)->putJson("/web-api/p/{$property->code}/settings", $this->fields([
            'name' => '', 'country_iso2' => 'ZZ', 'timezone' => 'Mars/Base', 'state_id' => 999999, 'website' => 'ftp://x', 'check_in_time' => '25:00',
        ]))->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['name', 'country_iso2', 'timezone', 'website', 'check_in_time']]]);
    }

    public function test_state_must_belong_to_the_country(): void
    {
        [$property, $owner] = $this->createPropertyWithOwner();
        $dubai = \App\Models\State::query()->where('country_iso2', 'AE')->value('id');

        $this->actingAs($owner)->putJson("/web-api/p/{$property->code}/settings", $this->fields(['country_iso2' => 'IN', 'state_id' => $dubai]))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['state_id']]]);
    }

    public function test_front_desk_sees_settings_read_only(): void
    {
        [$property] = $this->createPropertyWithOwner();
        $desk = $this->addMember($property, 'front_desk');

        $this->actingAs($desk)->get("/p/{$property->code}/settings")->assertOk()->assertSee('"can_update":false', false);
        $this->actingAs($desk)->putJson("/web-api/p/{$property->code}/settings", $this->fields())->assertForbidden();
    }

    public function test_expired_subscription_blocks_changes(): void
    {
        [$property, $owner] = $this->createPropertyWithOwner();
        \App\Models\Subscription::query()->where('property_id', $property->id)->update(['status' => 'expired']);

        $this->actingAs($owner)->putJson("/web-api/p/{$property->code}/settings", $this->fields())->assertStatus(402);
        $this->actingAs($owner)->get("/p/{$property->code}/dashboard")->assertOk();
    }

    public function test_signed_in_user_registers_a_first_property(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->get('/home')->assertRedirect(route('properties.create'));
        $this->get('/properties/new')->assertOk();

        $code = $this->postJson('/web-api/properties', $this->fields(['name' => 'My First Inn']))->assertCreated()->json('code');

        $property = Property::query()->where('code', $code)->firstOrFail();
        $this->assertSame('onboarding', $property->status);
        $this->assertMatchesRegularExpression('/^P\d{4,6}$/', $code);
        $this->assertDatabaseHas('property_users', ['property_id' => $property->id, 'user_id' => $user->id, 'is_owner' => true]);
        $this->assertDatabaseHas('subscriptions', ['property_id' => $property->id, 'status' => 'trial']);
        $this->get('/home')->assertRedirect(route('property.dashboard', $code));
    }

    public function test_user_with_several_properties_gets_the_picker(): void
    {
        [$a, $owner] = $this->createPropertyWithOwner(['name' => 'Alpha Hotel']);
        [$b] = $this->createPropertyWithOwner(['name' => 'Beta Hotel']);
        \App\Models\PropertyUser::query()->withoutGlobalScope('property')->create([
            'property_id' => $b->id, 'user_id' => $owner->id, 'status' => 'active', 'joined_at' => now(),
            'role_id' => \App\Models\Role::query()->whereNull('property_id')->where('code', 'hotel_manager')->value('id'),
        ]);

        $this->actingAs($owner)->get('/properties')->assertOk()->assertSee('Alpha Hotel')->assertSee('Beta Hotel');
    }
}
