<?php

namespace Tests\Feature\Property;

use App\Models\AuditLog;
use App\Models\PhysicalUnit;
use App\Models\Property;
use App\Models\PropertyUser;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Accommodation\AccommodationTestCase;

/** Copy Property: set-up is copied, operational data never is. */
class PropertyCopyTest extends AccommodationTestCase
{
    private function rowsOf(string $table, Property $property): int
    {
        return DB::table($table)->where('property_id', $property->id)->count();
    }

    public function test_owner_copies_the_property_with_its_set_up(): void
    {
        $this->makeRoomType(['code' => 'DLX', 'name' => 'Deluxe'], 3);
        $this->makeRoomType(['code' => 'STE', 'name' => 'Suite'], 2);

        $res = $this->actingAs($this->owner)->postJson($this->api('/copy'), ['name' => 'Main Hotel Two', 'include_rooms' => true])
            ->assertCreated();

        $copy = Property::query()->where('code', $res->json('code'))->firstOrFail();
        $this->assertNotSame($this->property->code, $copy->code);
        $this->assertSame('Main Hotel Two', $copy->name);
        $this->assertSame($this->property->currency_code, $copy->currency_code);
        $this->assertSame($this->property->timezone, $copy->timezone);
        foreach (['rate_plans', 'room_types', 'room_type_rate_plans', 'tax_rules', 'cancellation_policies', 'property_age_bands', 'property_languages'] as $table) {
            $this->assertSame($this->rowsOf($table, $this->property), $this->rowsOf($table, $copy), "{$table} copied");
        }
        $this->assertSame(5, PhysicalUnit::query()->withoutGlobalScope('property')->where('property_id', $copy->id)->count());
        $this->assertSame(0, $this->rowsOf('reservations', $copy));
        $this->assertSame(0, $this->rowsOf('guests', $copy));

        // Copied rows point at the copy's own records, never at the source's.
        $foreign = DB::table('room_type_rate_plans as p')->join('room_types as r', 'r.id', '=', 'p.room_type_id')
            ->where('p.property_id', $copy->id)->where('r.property_id', '!=', $copy->id)->count();
        $this->assertSame(0, $foreign);

        $this->assertDatabaseHas('property_users', ['property_id' => $copy->id, 'user_id' => $this->owner->id, 'is_owner' => true]);
        $this->assertTrue(AuditLog::query()->where('action', 'property.copied')->where('property_id', $copy->id)->exists());
        // Rate plan, room types and rooms exist, so the copy is ready to use.
        $this->assertSame('active', $copy->status);
        $this->assertStringContainsString("/p/{$copy->code}/properties", $res->json('redirect'));
    }

    public function test_rooms_are_optional(): void
    {
        $this->makeRoomType([], 2);

        $res = $this->actingAs($this->owner)->postJson($this->api('/copy'), ['name' => 'No Rooms Copy', 'include_rooms' => false])->assertCreated();
        $copy = Property::query()->where('code', $res->json('code'))->firstOrFail();

        $this->assertSame(0, PhysicalUnit::query()->withoutGlobalScope('property')->where('property_id', $copy->id)->count());
        $this->assertSame('onboarding', $copy->status);
    }

    public function test_manager_keeps_access_to_the_copy(): void
    {
        $manager = $this->member('hotel_manager');

        $res = $this->actingAs($manager)->postJson($this->api('/copy'), ['name' => 'Managed Copy'])->assertCreated();
        $copy = Property::query()->where('code', $res->json('code'))->firstOrFail();

        $this->assertTrue(PropertyUser::query()->withoutGlobalScope('property')->where('property_id', $copy->id)->where('user_id', $manager->id)->where('is_owner', false)->exists());
        $this->assertTrue(PropertyUser::query()->withoutGlobalScope('property')->where('property_id', $copy->id)->where('user_id', $this->owner->id)->where('is_owner', true)->exists());
        $this->actingAs($manager)->get("/p/{$copy->code}/dashboard")->assertOk();
    }

    public function test_validation_and_permissions(): void
    {
        $this->actingAs($this->owner)->postJson($this->api('/copy'), ['name' => ''])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['name']]]);

        $frontDesk = $this->member('front_desk');
        $this->actingAs($frontDesk)->postJson($this->api('/copy'), ['name' => 'Nope'])->assertForbidden();

        // Another property's owner cannot copy this property.
        $this->actingAs($this->otherOwner)->postJson($this->api('/copy'), ['name' => 'Nope'])->assertNotFound();
        $this->assertFalse(Property::query()->where('name', 'Nope')->exists());
    }

    public function test_expired_subscription_blocks_copying(): void
    {
        Subscription::query()->where('property_id', $this->property->id)->update(['status' => 'expired']);

        $this->actingAs($this->owner)->postJson($this->api('/copy'), ['name' => 'Late Copy'])->assertStatus(402);
    }

    public function test_platform_admin_copies_any_property(): void
    {
        $admin = $this->makePlatformAdmin();
        $this->makeRoomType([], 1);

        $res = $this->actingAs($admin)->postJson("/web-api/admin/properties/{$this->property->code}/copy", ['name' => 'Admin Copy', 'include_rooms' => true])
            ->assertCreated();
        $copy = Property::query()->where('code', $res->json('code'))->firstOrFail();
        // The copy belongs to the source property's owner, not to the E2X staff member.
        $this->assertDatabaseHas('property_users', ['property_id' => $copy->id, 'user_id' => $this->owner->id, 'is_owner' => true]);
        $this->assertDatabaseMissing('property_users', ['property_id' => $copy->id, 'user_id' => $admin->id]);

        $this->actingAs($admin)->postJson('/web-api/admin/properties/P999999/copy', ['name' => 'X'])->assertNotFound();
        $this->actingAs($this->owner)->postJson("/web-api/admin/properties/{$this->property->code}/copy", ['name' => 'X'])->assertForbidden();
    }
}
