<?php

namespace Tests\Feature\Subscription;

use App\Domain\Subscription\SubscriptionService;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Tests\TestCase;

/** Expiry is enforced on the server and never deletes data; plan limits are applied. */
class SubscriptionEnforcementTest extends TestCase
{
    public function test_ended_period_moves_to_grace_then_expired_without_the_daily_job(): void
    {
        [$property, $owner] = $this->createPropertyWithOwner();
        $sub = Subscription::query()->where('property_id', $property->id)->firstOrFail();
        $grace = $sub->plan->grace_days ?? (int) config('ozepms.subscription.default_grace_days');
        $service = app(SubscriptionService::class);

        $sub->update(['status' => 'active', 'ends_on' => now()->subDay()->toDateString()]);
        $state = $service->state($property->refresh());
        $this->assertSame($grace > 0 ? 'grace' : 'expired', $state['status']);

        $sub->update(['ends_on' => now()->subDays($grace + 2)->toDateString()]);
        $state = $service->state($property->refresh());
        $this->assertSame('expired', $state['status']);
        $this->assertTrue($state['read_only']);

        // Read access stays, every change is refused, nothing is deleted.
        $this->actingAs($owner)->get("/p/{$property->code}/settings")->assertOk();
        $this->actingAs($owner)->putJson("/web-api/p/{$property->code}/settings", ['name' => 'Changed'])
            ->assertStatus(402)->assertJsonPath('error.code', 'SUBSCRIPTION_INACTIVE');
        $this->assertNotSame('Changed', $property->refresh()->name);
        $this->assertDatabaseHas('properties', ['id' => $property->id, 'deleted_at' => null]);
    }

    public function test_daily_job_records_the_new_status(): void
    {
        [$property] = $this->createPropertyWithOwner();
        Subscription::query()->where('property_id', $property->id)->update(['status' => 'active', 'ends_on' => now()->subDays(60)->toDateString()]);

        app(SubscriptionService::class)->refreshStatuses();

        $this->assertContains(Subscription::query()->where('property_id', $property->id)->value('status'), ['grace', 'expired']);
        $this->assertDatabaseHas('audit_logs', ['property_id' => $property->id]);
    }

    public function test_user_limit_of_the_plan_is_enforced(): void
    {
        [$property, $owner] = $this->createPropertyWithOwner();
        $plan = Subscription::query()->where('property_id', $property->id)->firstOrFail()->plan;
        SubscriptionPlan::query()->whereKey($plan->id)->update(['max_users' => 2]);
        $role = \App\Models\Role::query()->whereNull('property_id')->where('code', 'front_desk')->value('id');

        $this->actingAs($owner)->postJson("/web-api/p/{$property->code}/users", ['name' => 'Second', 'email' => 'second@example.test', 'role' => 'front_desk', 'role_id' => $role])
            ->assertCreated();
        $this->actingAs($owner)->postJson("/web-api/p/{$property->code}/users", ['name' => 'Third', 'email' => 'third@example.test', 'role' => 'front_desk', 'role_id' => $role])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['email']]]);
    }
}
