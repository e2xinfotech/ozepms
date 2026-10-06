<?php

namespace Tests\Feature\Admin;

use App\Models\PropertyUser;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Super Admin → Manage owner. */
class ChangeOwnerTest extends TestCase
{
    private function membership(int $propertyId, int $userId): ?PropertyUser
    {
        return PropertyUser::query()->withoutGlobalScope('property')->where('property_id', $propertyId)->where('user_id', $userId)->first();
    }

    public function test_property_is_handed_over_and_previous_owner_stays_as_manager(): void
    {
        Notification::fake();
        $admin = $this->makePlatformAdmin();
        [$property, $oldOwner] = $this->createPropertyWithOwner();

        $this->actingAs($admin)->postJson("/web-api/admin/properties/{$property->code}/owner", ['name' => 'Nina New', 'email' => 'nina@example.test'])
            ->assertOk()->assertJsonPath('property.owner.email', 'nina@example.test');

        $new = User::query()->where('email', 'nina@example.test')->firstOrFail();
        $this->assertSame('invited', $new->status);
        $this->assertTrue((bool) $this->membership($property->id, $new->id)->is_owner);
        $old = $this->membership($property->id, $oldOwner->id);
        $this->assertFalse((bool) $old->is_owner);
        $this->assertSame('active', $old->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'property.owner_changed', 'property_id' => $property->id]);
        $this->assertSame(1, PropertyUser::query()->withoutGlobalScope('property')->where('property_id', $property->id)->where('is_owner', true)->count());
    }

    public function test_validation_and_permissions(): void
    {
        $admin = $this->makePlatformAdmin();
        [$property, $owner] = $this->createPropertyWithOwner();

        $this->actingAs($admin)->postJson("/web-api/admin/properties/{$property->code}/owner", ['name' => '', 'email' => 'nope'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['name', 'email']]]);
        $this->actingAs($owner)->postJson("/web-api/admin/properties/{$property->code}/owner", ['name' => 'X', 'email' => 'x@example.test'])
            ->assertForbidden();
        $this->actingAs($admin)->postJson('/web-api/admin/properties/P999999/owner', ['name' => 'X', 'email' => 'x@example.test'])
            ->assertNotFound();
    }
}
