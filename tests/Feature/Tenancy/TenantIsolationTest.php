<?php

namespace Tests\Feature\Tenancy;

use App\Models\PropertyUser;
use Tests\TestCase;

/** A property's data is only reachable through that property's URL and by its members. */
class TenantIsolationTest extends TestCase
{
    public function test_members_cannot_open_another_property(): void
    {
        [$mine, $owner] = $this->createPropertyWithOwner();
        [$other] = $this->createPropertyWithOwner();

        $this->actingAs($owner)->get("/p/{$mine->code}/dashboard")->assertOk();
        $this->actingAs($owner)->get("/p/{$other->code}/dashboard")->assertNotFound();
        $this->actingAs($owner)->get("/p/{$other->code}/users")->assertNotFound();
        $this->actingAs($owner)->getJson("/web-api/p/{$other->code}/roles")->assertNotFound();
    }

    public function test_unknown_property_code_is_not_found(): void
    {
        [, $owner] = $this->createPropertyWithOwner();

        $this->actingAs($owner)->get('/p/P999999/dashboard')->assertNotFound();
    }

    public function test_users_of_another_property_are_not_visible(): void
    {
        [$mine, $owner] = $this->createPropertyWithOwner();
        [$other] = $this->createPropertyWithOwner();
        $stranger = $this->addMember($other, 'front_desk');

        $this->actingAs($owner)->getJson("/web-api/p/{$mine->code}/users/{$stranger->public_id}")->assertNotFound();
        $this->actingAs($owner)->putJson("/web-api/p/{$mine->code}/users/{$stranger->public_id}", ['name' => 'Taken over'])->assertNotFound();
        $this->actingAs($owner)->deleteJson("/web-api/p/{$mine->code}/users/{$stranger->public_id}")->assertNotFound();
        $this->actingAs($owner)->postJson("/web-api/p/{$mine->code}/users/{$stranger->public_id}/password-link")->assertNotFound();

        $this->assertNotSame('Taken over', $stranger->refresh()->name);
        $this->assertDatabaseHas('property_users', ['property_id' => $other->id, 'user_id' => $stranger->id]);
    }

    public function test_users_list_only_shows_this_propertys_members(): void
    {
        [$mine, $owner] = $this->createPropertyWithOwner();
        [$other] = $this->createPropertyWithOwner();
        $this->addMember($mine, 'front_desk', ['name' => 'Visible Colleague']);
        $this->addMember($other, 'front_desk', ['name' => 'Hidden Stranger']);

        $this->actingAs($owner)->get("/p/{$mine->code}/users")
            ->assertOk()->assertSee('Visible Colleague')->assertDontSee('Hidden Stranger');
    }

    public function test_property_details_of_a_non_member_property_are_not_found(): void
    {
        [$mine, $owner] = $this->createPropertyWithOwner();
        [$other] = $this->createPropertyWithOwner();

        $this->actingAs($owner)->getJson("/web-api/p/{$mine->code}/properties/{$mine->code}")->assertOk()->assertJsonPath('property.code', $mine->code);
        $this->actingAs($owner)->getJson("/web-api/p/{$mine->code}/properties/{$other->code}")->assertNotFound();
    }

    public function test_properties_page_lists_only_my_properties(): void
    {
        [$mine, $owner] = $this->createPropertyWithOwner(['name' => 'Mine Palace']);
        $this->createPropertyWithOwner(['name' => 'Their Palace']);

        $this->actingAs($owner)->get("/p/{$mine->code}/properties")->assertOk()->assertSee('Mine Palace')->assertDontSee('Their Palace');
    }

    public function test_custom_roles_of_another_property_are_not_found(): void
    {
        [$mine, $owner] = $this->createPropertyWithOwner();
        [$other, $otherOwner] = $this->createPropertyWithOwner();

        $code = $this->actingAs($otherOwner)->postJson("/web-api/p/{$other->code}/roles", [
            'name' => 'Night Auditor', 'color' => 'teal', 'permissions' => ['reservations.view'],
        ])->assertCreated()->json('role.code');

        $this->actingAs($owner)->putJson("/web-api/p/{$mine->code}/roles/{$code}", [
            'name' => 'Hijacked', 'color' => 'red', 'permissions' => [],
        ])->assertNotFound();
        $this->actingAs($owner)->deleteJson("/web-api/p/{$mine->code}/roles/{$code}")->assertNotFound();
    }

    public function test_disabled_membership_loses_access(): void
    {
        [$property] = $this->createPropertyWithOwner();
        $manager = $this->addMember($property, 'hotel_manager');

        PropertyUser::query()->withoutGlobalScope('property')->where('user_id', $manager->id)->update(['status' => 'disabled']);

        $this->actingAs($manager)->get("/p/{$property->code}/dashboard")->assertNotFound();
    }

    public function test_suspended_property_is_closed_to_members(): void
    {
        [$property, $owner] = $this->createPropertyWithOwner();
        $property->forceFill(['status' => 'suspended'])->save();

        $this->actingAs($owner)->get("/p/{$property->code}/dashboard")->assertForbidden();
    }

    public function test_platform_staff_open_any_property_in_support_mode(): void
    {
        [$property] = $this->createPropertyWithOwner();
        $admin = $this->makePlatformAdmin();

        $this->actingAs($admin)->get("/p/{$property->code}/dashboard")->assertOk()->assertSee('"support_mode":true', false);
    }
}
