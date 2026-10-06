<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Property;
use Tests\TestCase;

/** Bulk Actions on Super Admin → Properties. */
class BulkActionsTest extends TestCase
{
    public function test_selected_properties_change_status_together(): void
    {
        $admin = $this->makePlatformAdmin();
        [$a] = $this->createPropertyWithOwner();
        [$b] = $this->createPropertyWithOwner();
        [$c] = $this->createPropertyWithOwner();

        $this->actingAs($admin)->postJson('/web-api/admin/properties/bulk-status', ['codes' => [$a->code, $b->code], 'status' => 'inactive'])
            ->assertOk()->assertJson(['changed' => 2]);

        $this->assertSame('inactive', $a->refresh()->status);
        $this->assertSame('inactive', $b->refresh()->status);
        $this->assertSame('active', $c->refresh()->status);
        $this->assertSame(2, AuditLog::query()->where('action', 'property.status_changed')->whereIn('property_id', [$a->id, $b->id])->count());

        // Already in the wanted state: nothing to change.
        $this->actingAs($admin)->postJson('/web-api/admin/properties/bulk-status', ['codes' => [$a->code], 'status' => 'inactive'])
            ->assertOk()->assertJson(['changed' => 0]);
        $this->actingAs($admin)->postJson('/web-api/admin/properties/bulk-status', ['codes' => [$a->code, $b->code], 'status' => 'active'])
            ->assertOk()->assertJson(['changed' => 2]);
    }

    public function test_validation(): void
    {
        $admin = $this->makePlatformAdmin();
        [$a] = $this->createPropertyWithOwner();

        $this->actingAs($admin)->postJson('/web-api/admin/properties/bulk-status', ['codes' => [], 'status' => 'inactive'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['codes']]]);
        $this->actingAs($admin)->postJson('/web-api/admin/properties/bulk-status', ['codes' => ['P999999'], 'status' => 'inactive'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['codes.0']]]);
        $this->actingAs($admin)->postJson('/web-api/admin/properties/bulk-status', ['codes' => [$a->code], 'status' => 'deleted'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['status']]]);
        $this->assertSame('active', $a->refresh()->status);
    }

    public function test_property_users_cannot_use_bulk_actions(): void
    {
        [$a, $owner] = $this->createPropertyWithOwner();

        $this->actingAs($owner)->postJson('/web-api/admin/properties/bulk-status', ['codes' => [$a->code], 'status' => 'inactive'])->assertForbidden();
        $this->assertSame('active', Property::query()->find($a->id)->status);
    }
}
