<?php

namespace Tests\Feature\Users;

use App\Models\PropertyUser;
use App\Models\User;
use App\Notifications\PasswordLinkNotification;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PropertyUsersTest extends TestCase
{
    private function url($property, string $path = ''): string
    {
        return "/web-api/p/{$property->code}/users{$path}";
    }

    public function test_owner_invites_a_new_user(): void
    {
        Notification::fake();
        [$property, $owner] = $this->createPropertyWithOwner();

        $res = $this->actingAs($owner)->postJson($this->url($property), [
            'name' => 'Priya Sharma', 'email' => 'Priya@Example.test', 'job_title' => 'Front Desk', 'role' => 'front_desk', 'locale' => 'fr',
        ])->assertCreated()->assertJsonPath('user.role', 'front_desk')->assertJsonPath('user.email', 'priya@example.test');

        $user = User::query()->where('email', 'priya@example.test')->firstOrFail();
        $this->assertSame('invited', $user->status);
        $this->assertSame($user->public_id, $res->json('user.id'));
        $this->assertDatabaseHas('property_users', ['property_id' => $property->id, 'user_id' => $user->id, 'status' => 'active']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'property_user.added', 'property_id' => $property->id]);
        Notification::assertSentTo($user, PasswordLinkNotification::class);
    }

    public function test_existing_account_is_added_without_a_new_password(): void
    {
        Notification::fake();
        [$property, $owner] = $this->createPropertyWithOwner();
        $existing = $this->makeUser(['email' => 'known@example.test']);
        $hash = $existing->password;

        $this->actingAs($owner)->postJson($this->url($property), ['name' => 'Known', 'email' => 'known@example.test', 'role' => 'housekeeping'])
            ->assertCreated()->assertJsonPath('message', __('users.added'));

        $this->assertSame($hash, $existing->refresh()->password);
        $this->assertSame('active', $existing->status);
    }

    public function test_adding_validation(): void
    {
        [$property, $owner] = $this->createPropertyWithOwner();
        $member = $this->addMember($property, 'front_desk');

        $this->actingAs($owner)->postJson($this->url($property), ['name' => '', 'email' => 'not-an-email', 'phone_e164' => '0123'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['name', 'email', 'role', 'phone_e164']]]);

        $this->postJson($this->url($property), ['name' => 'X', 'email' => 'x@example.test', 'role' => 'no_such_role'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['role']]]);

        $this->postJson($this->url($property), ['name' => 'X', 'email' => 'y@example.test', 'role' => 'owner'])
            ->assertStatus(422);

        $this->postJson($this->url($property), ['name' => 'Dup', 'email' => $member->email, 'role' => 'front_desk'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['email']]]);
    }

    public function test_users_without_users_manage_are_refused(): void
    {
        [$property] = $this->createPropertyWithOwner();
        $frontDesk = $this->addMember($property, 'front_desk');
        $colleague = $this->addMember($property, 'housekeeping');

        $this->actingAs($frontDesk)->postJson($this->url($property), ['name' => 'X', 'email' => 'x@example.test', 'role' => 'front_desk'])->assertForbidden();
        $this->actingAs($frontDesk)->getJson($this->url($property, "/{$colleague->public_id}"))->assertForbidden();
        $this->actingAs($frontDesk)->putJson($this->url($property, "/{$colleague->public_id}"), ['status' => 'disabled'])->assertForbidden();
        $this->actingAs($frontDesk)->getJson("/web-api/p/{$property->code}/roles")->assertForbidden();
    }

    public function test_show_returns_details_permissions_and_activity(): void
    {
        [$property, $owner] = $this->createPropertyWithOwner();
        $member = $this->addMember($property, 'front_desk');

        $this->actingAs($owner)->getJson($this->url($property, "/{$member->public_id}"))
            ->assertOk()
            ->assertJsonPath('user.id', $member->public_id)
            ->assertJsonPath('user.role', 'front_desk')
            ->assertJsonStructure(['user' => ['permissions', 'properties', 'activity', 'two_factor']])
            ->assertJsonMissingPath('user.password');
    }

    public function test_update_role_details_and_status(): void
    {
        [$property, $owner] = $this->createPropertyWithOwner();
        $member = $this->addMember($property, 'front_desk');

        $this->actingAs($owner)->putJson($this->url($property, "/{$member->public_id}"), ['name' => 'Renamed', 'role' => 'reservations'])
            ->assertOk()->assertJsonPath('user.role', 'reservations')->assertJsonPath('user.name', 'Renamed');

        $this->putJson($this->url($property, "/{$member->public_id}"), ['status' => 'disabled'])->assertOk()->assertJsonPath('user.status', 'disabled');
        $this->assertSame('disabled', PropertyUser::query()->withoutGlobalScope('property')->where('user_id', $member->id)->value('status'));

        $this->putJson($this->url($property, "/{$member->public_id}"), ['status' => 'archived'])->assertStatus(422);
    }

    public function test_owner_is_protected(): void
    {
        [$property, $owner] = $this->createPropertyWithOwner();
        $manager = $this->addMember($property, 'hotel_manager');

        $this->actingAs($manager)->putJson($this->url($property, "/{$owner->public_id}"), ['role' => 'front_desk'])->assertStatus(422);
        $this->actingAs($manager)->putJson($this->url($property, "/{$owner->public_id}"), ['status' => 'disabled'])->assertStatus(422);
        $this->actingAs($manager)->deleteJson($this->url($property, "/{$owner->public_id}"))->assertStatus(422);
        $this->assertDatabaseHas('property_users', ['user_id' => $owner->id, 'property_id' => $property->id, 'status' => 'active']);
    }

    public function test_remove_and_password_link(): void
    {
        Notification::fake();
        [$property, $owner] = $this->createPropertyWithOwner();
        $member = $this->addMember($property, 'front_desk');

        $this->actingAs($owner)->postJson($this->url($property, "/{$member->public_id}/password-link"))->assertOk();
        Notification::assertSentTo($member, PasswordLinkNotification::class);

        $this->deleteJson($this->url($property, "/{$member->public_id}"))->assertOk();
        $this->assertDatabaseMissing('property_users', ['user_id' => $member->id, 'property_id' => $property->id, 'status' => 'active']);
        $this->assertDatabaseHas('users', ['id' => $member->id]);
    }

    public function test_unknown_user_is_not_found(): void
    {
        [$property, $owner] = $this->createPropertyWithOwner();

        $this->actingAs($owner)->getJson($this->url($property, '/01JUNKUNKNOWNID0000000000'))->assertNotFound();
    }

    public function test_users_page_filters_and_paginates(): void
    {
        [$property, $owner] = $this->createPropertyWithOwner();
        foreach (range(1, 12) as $i) {
            $this->addMember($property, $i % 2 ? 'front_desk' : 'housekeeping', ['name' => sprintf('Member %02d', $i)]);
        }

        $page = $this->actingAs($owner)->get("/p/{$property->code}/users?role=front_desk&per_page=10")->assertOk();
        $props = $this->pageProps($page);
        $this->assertSame(6, $props['meta']['total']);
        $this->assertCount(6, $props['rows']);
        $this->assertSame(['front_desk'], array_values(array_unique(array_column($props['rows'], 'role'))));

        $props = $this->pageProps($this->get("/p/{$property->code}/users?per_page=10&page=2"));
        $this->assertSame(2, $props['meta']['page']);
        $this->assertSame(13, $props['meta']['total']);
        $this->assertSame(13, $props['kpis']['total']);

        $props = $this->pageProps($this->get("/p/{$property->code}/users?q=Member%2003"));
        $this->assertSame(['Member 03'], array_column($props['rows'], 'name'));
    }

    /** Props embedded in the page by App\Support\Page. */
    private function pageProps($response): array
    {
        preg_match('#<script type="application/json" id="oz-page"[^>]*>(.*?)</script>#s', $response->getContent(), $m);

        return json_decode(html_entity_decode($m[1] ?? '{}'), true)['props'] ?? [];
    }
}
