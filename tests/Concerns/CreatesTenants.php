<?php

namespace Tests\Concerns;

use App\Domain\Property\PropertyService;
use App\Models\Property;
use App\Models\PropertyUser;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\PropertyContext;
use Illuminate\Support\Str;

/**
 * Helpers for building tenants in tests.
 *
 *   [$property, $owner] = $this->createPropertyWithOwner();
 *   $frontDesk = $this->addMember($property, 'front_desk');
 *   $this->actingAs($frontDesk)->get("/p/{$property->code}/dashboard")->assertOk();
 */
trait CreatesTenants
{
    protected function makeUser(array $attributes = []): User
    {
        // Reloaded so every column is present, as it is for users loaded during a real request.
        return User::query()->create(array_merge([
            'name' => 'Test User '.Str::random(4),
            'email' => Str::lower(Str::random(10)).'@example.test',
            'password' => 'Secret-Pass-123',
            'status' => 'active',
            'locale' => 'en',
        ], $attributes))->refresh();
    }

    protected function makePlatformAdmin(array $attributes = []): User
    {
        $user = $this->makeUser(array_merge(['is_platform_user' => true], $attributes));
        $user->platformRoles()->sync([Role::query()->whereNull('property_id')->where('code', 'super_admin')->value('id')]);

        return $user;
    }

    /** @return array{0: Property, 1: User} */
    protected function createPropertyWithOwner(array $attributes = []): array
    {
        $owner = $this->makeUser();
        $creator = $this->makePlatformAdmin();
        $property = app(PropertyService::class)->create(array_merge([
            'name' => 'Hotel '.Str::random(5),
            'property_type_id' => 1,
            'country_iso2' => 'IN',
            'currency_code' => 'INR',
            'timezone' => 'Asia/Kolkata',
            'default_language' => 'en',
            'status' => 'active',
        ], $attributes), $owner, SubscriptionPlan::query()->first(), $creator);

        app(PropertyContext::class)->clear();

        return [$property->refresh(), $owner];
    }

    protected function addMember(Property $property, string $roleCode, array $userAttributes = []): User
    {
        $user = $this->makeUser($userAttributes);
        PropertyUser::query()->withoutGlobalScope('property')->create([
            'property_id' => $property->id,
            'user_id' => $user->id,
            'role_id' => Role::query()->whereNull('property_id')->where('code', $roleCode)->value('id'),
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return $user;
    }

    /** Selects a property for code that runs outside an HTTP request (services, jobs). */
    protected function inProperty(Property $property): void
    {
        app(PropertyContext::class)->set($property, null, true);
    }
}
