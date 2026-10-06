<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Every Phase 1 event of the audit list (spec §49) is written with user, property, entity, IP and time. */
class AuditTrailTest extends TestCase
{
    public function test_phase_one_events_are_audited(): void
    {
        Notification::fake();
        [$property, $owner] = $this->createPropertyWithOwner();
        $admin = $this->makePlatformAdmin();

        // Login and logout
        $this->postJson('/web-api/auth/login', ['email' => $owner->email, 'password' => 'Secret-Pass-123'])->assertOk();
        $this->postJson('/web-api/auth/logout')->assertSuccessful();

        // Property update, user creation, permission change, subscription change
        $this->actingAs($owner)->putJson("/web-api/p/{$property->code}/settings", [
            'name' => 'Renamed Hotel', 'property_type_id' => 1, 'country_iso2' => 'IN', 'currency_code' => 'INR',
            'timezone' => 'Asia/Kolkata', 'default_language' => 'en',
        ])->assertOk();
        $this->actingAs($owner)->postJson("/web-api/p/{$property->code}/users", ['name' => 'Asha', 'email' => 'asha@example.test', 'role' => 'front_desk'])->assertCreated();
        $this->actingAs($owner)->postJson("/web-api/p/{$property->code}/roles", ['name' => 'Night Audit', 'color' => 'teal', 'permissions' => ['property.view']])->assertCreated();
        $this->actingAs($admin)->postJson("/web-api/admin/properties/{$property->code}/subscription", [
            'plan_id' => SubscriptionPlan::query()->value('id'), 'starts_on' => now()->toDateString(), 'ends_on' => now()->addYear()->toDateString(),
        ])->assertOk();

        foreach (['auth.login', 'auth.logout', 'property.created', 'property.updated', 'property_user.added', 'role.created', 'subscription.active'] as $action) {
            $this->assertTrue(AuditLog::query()->where('action', $action)->exists(), "{$action} is audited");
        }

        $update = AuditLog::query()->where('action', 'property.updated')->firstOrFail();
        $this->assertSame($property->id, $update->property_id);
        $this->assertSame($owner->id, $update->user_id);
        $this->assertSame('property', $update->entity_type);
        $this->assertNotNull($update->entity_id);
        $this->assertNotNull($update->ip);
        $this->assertNotNull($update->created_at);
        $this->assertArrayHasKey('after', (array) $update->changes);
    }
}
