<?php

namespace Tests\Feature\Users;

use Tests\TestCase;

class RolesTest extends TestCase
{
    public function test_index_lists_system_roles_and_catalogue(): void
    {
        [$property, $owner] = $this->createPropertyWithOwner();

        $res = $this->actingAs($owner)->getJson("/web-api/p/{$property->code}/roles")->assertOk();

        $codes = array_column($res->json('roles'), 'code');
        $this->assertContains('owner', $codes);
        $this->assertContains('front_desk', $codes);
        $this->assertNotEmpty($res->json('catalogue'));
        $this->assertFalse(collect($res->json('roles'))->firstWhere('code', 'owner')['assignable']);
    }

    public function test_custom_role_lifecycle(): void
    {
        [$property, $owner] = $this->createPropertyWithOwner();
        $base = "/web-api/p/{$property->code}/roles";

        $code = $this->actingAs($owner)->postJson($base, [
            'name' => 'Night Auditor', 'description' => 'Night shift', 'color' => 'teal', 'permissions' => ['reservations.view', 'folio.view'],
        ])->assertCreated()->assertJsonPath('role.is_system', false)->json('role.code');

        $this->putJson("{$base}/{$code}", ['name' => 'Night Audit', 'color' => 'violet', 'permissions' => ['reservations.view']])
            ->assertOk()->assertJsonPath('role.name', 'Night Audit')->assertJsonPath('role.permissions', ['reservations.view']);

        // A user with the custom role gets exactly its permissions.
        $this->postJson("/web-api/p/{$property->code}/users", ['name' => 'Auditor', 'email' => 'audit@example.test', 'role' => $code])->assertCreated();
        $this->deleteJson("{$base}/{$code}")->assertStatus(422);

        $this->deleteJson("/web-api/p/{$property->code}/users/".\App\Models\User::query()->where('email', 'audit@example.test')->value('public_id'))->assertOk();
        $this->deleteJson("{$base}/{$code}")->assertOk();
        $this->assertDatabaseMissing('roles', ['code' => $code, 'property_id' => $property->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'role.deleted', 'property_id' => $property->id]);
    }

    public function test_role_validation(): void
    {
        [$property, $owner] = $this->createPropertyWithOwner();

        $this->actingAs($owner)->postJson("/web-api/p/{$property->code}/roles", ['name' => '', 'color' => 'neon', 'permissions' => ['platform.dashboard']])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['name', 'color', 'permissions.0']]]);
    }

    public function test_system_roles_are_read_only(): void
    {
        [$property, $owner] = $this->createPropertyWithOwner();

        $this->actingAs($owner)->putJson("/web-api/p/{$property->code}/roles/front_desk", ['name' => 'Desk', 'color' => 'red', 'permissions' => []])->assertStatus(422);
        $this->actingAs($owner)->deleteJson("/web-api/p/{$property->code}/roles/front_desk")->assertStatus(422);
    }

    public function test_unknown_role_is_not_found(): void
    {
        [$property, $owner] = $this->createPropertyWithOwner();

        $this->actingAs($owner)->putJson("/web-api/p/{$property->code}/roles/nope", ['name' => 'X', 'color' => 'red', 'permissions' => []])->assertNotFound();
    }

    public function test_front_desk_cannot_manage_roles(): void
    {
        [$property] = $this->createPropertyWithOwner();
        $desk = $this->addMember($property, 'front_desk');

        $this->actingAs($desk)->postJson("/web-api/p/{$property->code}/roles", ['name' => 'X', 'color' => 'red', 'permissions' => []])->assertForbidden();
    }
}
