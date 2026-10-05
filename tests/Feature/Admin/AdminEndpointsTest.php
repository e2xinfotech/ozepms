<?php

namespace Tests\Feature\Admin;

use App\Models\Property;
use App\Models\SubscriptionPlan;
use App\Models\SystemErrorEvent;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdminEndpointsTest extends TestCase
{
    private function propertyFields(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ocean View Resort', 'property_type_id' => 2, 'country_iso2' => 'IN', 'currency_code' => 'INR',
            'timezone' => 'Asia/Kolkata', 'default_language' => 'en', 'status' => 'active',
            'owner' => ['name' => 'Olivia Owner', 'email' => 'olivia@example.test'],
            'plan_id' => SubscriptionPlan::query()->value('id'),
        ], $overrides);
    }

    public function test_property_users_cannot_reach_platform_endpoints(): void
    {
        [$property, $owner] = $this->createPropertyWithOwner();

        $this->actingAs($owner)->postJson('/web-api/admin/properties', $this->propertyFields())->assertForbidden();
        $this->actingAs($owner)->getJson("/web-api/admin/properties/{$property->code}")->assertForbidden();
        $this->actingAs($owner)->postJson('/web-api/admin/users', ['name' => 'X', 'email' => 'x@example.test', 'roles' => ['super_admin']])->assertForbidden();
        $this->actingAs($owner)->postJson('/web-api/admin/plans', [])->assertForbidden();
        $this->actingAs($owner)->postJson('/web-api/admin/system/errors/abc/resolve')->assertForbidden();
        $this->assertFalse(User::query()->where('email', 'x@example.test')->exists());
    }

    public function test_register_property_with_owner_and_plan(): void
    {
        Notification::fake();
        $admin = $this->makePlatformAdmin();

        $res = $this->actingAs($admin)->postJson('/web-api/admin/properties', $this->propertyFields())->assertCreated();

        $property = Property::query()->where('code', $res->json('code'))->firstOrFail();
        $owner = User::query()->where('email', 'olivia@example.test')->firstOrFail();
        $this->assertSame('active', $property->status);
        $this->assertDatabaseHas('property_users', ['property_id' => $property->id, 'user_id' => $owner->id, 'is_owner' => true]);
        $this->assertDatabaseHas('subscriptions', ['property_id' => $property->id, 'status' => 'trial']);
        $this->assertStringContainsString('selected='.$property->code, $res->json('redirect'));
    }

    public function test_register_property_validation(): void
    {
        $admin = $this->makePlatformAdmin();

        $this->actingAs($admin)->postJson('/web-api/admin/properties', $this->propertyFields(['name' => '', 'owner' => ['email' => 'bad'], 'status' => 'deleted']))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['name', 'owner.name', 'owner.email', 'status']]]);
    }

    public function test_update_status_and_subscription(): void
    {
        [$property] = $this->createPropertyWithOwner();
        $admin = $this->makePlatformAdmin();

        $this->actingAs($admin)->getJson("/web-api/admin/properties/{$property->code}")->assertOk()->assertJsonPath('property.code', $property->code);
        $this->getJson('/web-api/admin/properties/P999999')->assertNotFound();

        $this->putJson("/web-api/admin/properties/{$property->code}", [
            'name' => 'Renamed Hotel', 'property_type_id' => 1, 'country_iso2' => 'FR', 'currency_code' => 'EUR', 'timezone' => 'Europe/Paris', 'default_language' => 'fr',
        ])->assertOk()->assertJsonPath('property.name', 'Renamed Hotel')->assertJsonPath('property.currency_code', 'EUR');

        $this->postJson("/web-api/admin/properties/{$property->code}/status", ['status' => 'inactive', 'reason' => 'Contract ended'])
            ->assertOk()->assertJsonPath('status', 'inactive');
        $this->postJson("/web-api/admin/properties/{$property->code}/status", ['status' => 'gone'])->assertStatus(422);

        $plan = SubscriptionPlan::query()->where('code', 'enterprise')->firstOrFail();
        $this->postJson("/web-api/admin/properties/{$property->code}/subscription", [
            'plan_id' => $plan->id, 'starts_on' => now()->toDateString(), 'ends_on' => now()->addYear()->toDateString(), 'price' => '99000.00',
        ])->assertOk()->assertJsonPath('subscription.plan', $plan->name)->assertJsonPath('subscription.status', 'active');

        $this->postJson("/web-api/admin/properties/{$property->code}/subscription", [
            'plan_id' => $plan->id, 'starts_on' => '2026-10-10', 'ends_on' => '2026-10-01',
        ])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['ends_on']]]);
    }

    public function test_platform_users(): void
    {
        Notification::fake();
        $admin = $this->makePlatformAdmin();

        $id = $this->actingAs($admin)->postJson('/web-api/admin/users', [
            'name' => 'Ahmed Mansoori', 'email' => 'ahmed@e2x.test', 'job_title' => 'IT Support', 'roles' => ['it_support'],
        ])->assertCreated()->assertJsonPath('user.roles', ['it_support'])->json('user.id');

        $this->postJson('/web-api/admin/users', ['name' => 'X', 'email' => 'y@e2x.test', 'roles' => ['owner']])->assertStatus(422);
        $this->postJson('/web-api/admin/users', ['name' => 'X', 'email' => 'z@e2x.test', 'roles' => []])->assertStatus(422);

        $this->getJson("/web-api/admin/users/{$id}")->assertOk()->assertJsonPath('user.is_platform', true);
        $this->putJson("/web-api/admin/users/{$id}", ['name' => 'Ahmed M.', 'roles' => ['it_support', 'super_admin']])
            ->assertOk()->assertJsonPath('user.name', 'Ahmed M.');

        $this->postJson("/web-api/admin/users/{$id}/status", ['status' => 'disabled'])->assertOk()->assertJsonPath('status', 'disabled');
        $this->postJson("/web-api/admin/users/{$id}/password-link")->assertOk();
        $this->getJson('/web-api/admin/users/01JUNKUNKNOWNID0000000000')->assertNotFound();
    }

    public function test_admin_cannot_disable_own_account(): void
    {
        $admin = $this->makePlatformAdmin();

        $this->actingAs($admin)->postJson("/web-api/admin/users/{$admin->public_id}/status", ['status' => 'disabled'])->assertStatus(422);
        $this->assertSame('active', $admin->refresh()->status);
    }

    public function test_plans(): void
    {
        $admin = $this->makePlatformAdmin();
        $plan = [
            'code' => 'boutique_plus', 'name' => 'Boutique Plus', 'price' => '2999.50', 'currency_code' => 'INR', 'billing_cycle' => 'yearly',
            'trial_days' => 30, 'grace_days' => 10, 'max_room_types' => 8, 'max_units' => null, 'max_users' => 5,
            'features' => ['booking_engine' => true, 'reports' => true], 'is_active' => true,
        ];

        $this->actingAs($admin)->postJson('/web-api/admin/plans', $plan)->assertCreated()
            ->assertJsonPath('plan.features.booking_engine', true)->assertJsonPath('plan.features.channel_manager', false)
            ->assertJsonPath('plan.max_units', null);
        $this->postJson('/web-api/admin/plans', $plan)->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['code']]]);

        $this->putJson('/web-api/admin/plans/boutique_plus', ['code' => 'changed'] + $plan)->assertStatus(422);
        unset($plan['code']);
        $this->putJson('/web-api/admin/plans/boutique_plus', ['price' => '3499.00', 'is_active' => false] + $plan)
            ->assertOk()->assertJsonPath('plan.price', '3499.00')->assertJsonPath('plan.is_active', false);
        $this->putJson('/web-api/admin/plans/missing', $plan)->assertNotFound();
    }

    public function test_resolve_error_group(): void
    {
        $admin = $this->makePlatformAdmin();
        $event = SystemErrorEvent::query()->create([
            'fingerprint' => sha1('test'), 'level' => 'error', 'source' => 'server', 'message' => 'Boom',
            'occurrences' => 3, 'first_seen_at' => now()->subHour(), 'last_seen_at' => now(),
        ]);

        $this->actingAs($admin)->postJson("/web-api/admin/system/errors/{$event->fingerprint}/resolve")->assertOk();
        $this->assertNotNull($event->refresh()->resolved_at);
        $this->postJson('/web-api/admin/system/errors/unknown/resolve')->assertNotFound();
    }

    public function test_it_support_sees_system_health_but_not_properties(): void
    {
        $support = $this->makeUser(['is_platform_user' => true]);
        $support->platformRoles()->sync([\App\Models\Role::query()->whereNull('property_id')->where('code', 'it_support')->value('id')]);

        $this->actingAs($support)->get('/admin/system')->assertOk();
        $this->actingAs($support)->get('/admin/audit')->assertOk();
        $this->actingAs($support)->get('/admin/properties')->assertForbidden();
        $this->actingAs($support)->postJson('/web-api/admin/properties', $this->propertyFields())->assertForbidden();
    }
}
