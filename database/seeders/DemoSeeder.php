<?php

namespace Database\Seeders;

use App\Domain\Property\PropertyService;
use App\Domain\Subscription\SubscriptionService;
use App\Infrastructure\Database\Tx;
use App\Models\Property;
use App\Models\PropertyUser;
use App\Models\Role;
use App\Models\State;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\PropertyContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Demo data for presentations and local development. Not part of the default
 * `db:seed`; run with:  php artisan db:seed --class=DemoSeeder
 *
 * Creates two properties (P1001, P1002 on a fresh database), an owner, a hotel
 * manager and a front-desk user, and a one-year subscription on the
 * Professional plan. Safe to run repeatedly: existing records are kept.
 * Module demo seeders (rooms, rates…) are called when they exist.
 */
class DemoSeeder extends Seeder
{
    /** @var array<int, array<string, mixed>> */
    private const PROPERTIES = [
        [
            'slug' => 'sunrise-grand-hotel', 'name' => 'Sunrise Grand Hotel', 'tagline' => 'Luxury redefined in Dubai', 'type' => 'hotel', 'star_rating' => 5,
            'country_iso2' => 'AE', 'state' => 'Dubayy', 'city' => 'Dubai', 'address_line1' => 'Sheikh Mohammed bin Rashid Blvd, Downtown Dubai',
            'postcode' => '00000', 'currency_code' => 'AED', 'timezone' => 'Asia/Dubai', 'phone' => '+971 4 123 4567', 'email' => 'stay@sunrisegrand.example',
            'website' => 'https://sunrisegrand.example', 'contact_person' => 'Amit Pandey', 'languages' => ['fr', 'de'],
        ],
        [
            'slug' => 'ocean-view-resort', 'name' => 'Ocean View Resort', 'tagline' => 'Beachfront luxury experience', 'type' => 'resort', 'star_rating' => 4,
            'country_iso2' => 'IN', 'state' => 'Goa', 'city' => 'Candolim', 'address_line1' => 'Fort Aguada Road', 'postcode' => '403515',
            'currency_code' => 'INR', 'timezone' => 'Asia/Kolkata', 'phone' => '+91 832 248 0000', 'email' => 'hello@oceanview.example',
            'website' => 'https://oceanview.example', 'contact_person' => 'Priya Sharma', 'tax_registration_no' => '30AABCO1234F1Z5', 'languages' => ['it'],
        ],
    ];

    /** e-mail => [name, job title, platform?, memberships: [slug => role code]] */
    private const USERS = [
        'owner@demo.ozepms.test' => ['Amit Pandey', 'Owner', ['sunrise-grand-hotel' => 'owner', 'ocean-view-resort' => 'owner']],
        'manager@demo.ozepms.test' => ['Rohan Mehta', 'Hotel Manager', ['sunrise-grand-hotel' => 'hotel_manager', 'ocean-view-resort' => 'hotel_manager']],
        'frontdesk@demo.ozepms.test' => ['Priya Sharma', 'Front Desk Agent', ['sunrise-grand-hotel' => 'front_desk']],
    ];

    /** @var array<int, array{0: string, 1: string, 2: string}> */
    private array $credentials = [];

    public function run(PropertyService $properties, SubscriptionService $subscriptions): void
    {
        // One transaction, so a failed run leaves no half-created demo accounts behind.
        $created = Tx::run(fn () => $this->seed($properties, $subscriptions));

        $this->report($created);

        // Module demo data (rooms, rate plans, taxes…) is added by the module's own seeder when present.
        if (class_exists(AccommodationDemoSeeder::class)) {
            $this->call(AccommodationDemoSeeder::class);
        }
        if (class_exists(InventoryDemoSeeder::class)) {
            $this->call(InventoryDemoSeeder::class);
        }
        if (class_exists(ReservationDemoSeeder::class)) {
            $this->call(ReservationDemoSeeder::class);
        }
        if (class_exists(BillingDemoSeeder::class)) {
            $this->call(BillingDemoSeeder::class);
        }
    }

    /** @return array<int, string> codes of the properties created by this run */
    private function seed(PropertyService $properties, SubscriptionService $subscriptions): array
    {
        $password = (string) config('ozepms.demo.password');
        $users = [];
        foreach (self::USERS as $email => [$name, $title]) {
            $users[$email] = $this->user($email, $name, $title, $password);
        }

        $owner = $users['owner@demo.ozepms.test'];
        $creator = User::query()->where('is_platform_user', true)->orderBy('id')->first() ?? $owner;
        $plan = SubscriptionPlan::query()->where('code', 'professional')->first() ?? SubscriptionPlan::query()->orderBy('sort_order')->first();

        $created = [];
        foreach (self::PROPERTIES as $definition) {
            $property = Property::query()->where('slug', $definition['slug'])->first();
            if (! $property) {
                $property = $properties->createWithLanguages($this->propertyFields($definition), $owner, $plan, $creator);
                if ($plan) {
                    $start = CarbonImmutable::today();
                    $subscriptions->assign($property, $plan, $start, $start->addYear(), $plan->price, $creator, 'Demo subscription');
                }
                $created[] = $property->code;
            }
            app(PropertyContext::class)->clear();

            foreach (self::USERS as $email => [, , $memberships]) {
                if (isset($memberships[$definition['slug']])) {
                    $this->membership($property, $users[$email], $memberships[$definition['slug']], $owner);
                }
            }
        }

        return $created;
    }

    private function user(string $email, string $name, string $title, string $password): User
    {
        $user = User::query()->where('email', $email)->first();
        if ($user) {
            return $user;
        }

        $user = User::query()->create([
            'name' => $name, 'email' => $email, 'job_title' => $title, 'password' => $password,
            'status' => 'active', 'locale' => 'en', 'is_platform_user' => false,
        ]);
        $user->forceFill(['email_verified_at' => now(), 'password_changed_at' => now()])->save();
        $this->credentials[] = [$email, $title, $password];

        return $user;
    }

    private function membership(Property $property, User $user, string $roleCode, User $by): void
    {
        $exists = PropertyUser::query()->withoutGlobalScope('property')
            ->where('property_id', $property->id)->where('user_id', $user->id)->exists();
        if ($exists) {
            return;
        }

        PropertyUser::query()->withoutGlobalScope('property')->create([
            'property_id' => $property->id,
            'user_id' => $user->id,
            'role_id' => Role::query()->whereNull('property_id')->where('code', $roleCode)->value('id'),
            'is_owner' => $roleCode === 'owner',
            'status' => 'active',
            'invited_by' => $by->id,
            'joined_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $d */
    private function propertyFields(array $d): array
    {
        return [
            'name' => $d['name'],
            'tagline' => $d['tagline'],
            'property_type_id' => \App\Models\PropertyType::query()->where('code', $d['type'])->value('id')
                ?? \App\Models\PropertyType::query()->orderBy('sort_order')->value('id'),
            'star_rating' => $d['star_rating'],
            'phone' => $d['phone'],
            'email' => $d['email'],
            'website' => $d['website'],
            'contact_person' => $d['contact_person'],
            'country_iso2' => $d['country_iso2'],
            'state_id' => State::query()->where('country_iso2', $d['country_iso2'])->where('name', $d['state'])->value('id'),
            'city' => $d['city'],
            'postcode' => $d['postcode'],
            'address_line1' => $d['address_line1'],
            'currency_code' => $d['currency_code'],
            'timezone' => $d['timezone'],
            'tax_registration_no' => $d['tax_registration_no'] ?? null,
            'default_language' => 'en',
            'languages' => $d['languages'],
            'status' => 'active',
        ];
    }

    /** @param  array<int, string>  $created */
    private function report(array $created): void
    {
        if (! $this->command) {
            return;
        }

        $codes = Property::query()->whereIn('slug', array_column(self::PROPERTIES, 'slug'))->orderBy('id')->pluck('code')->implode(', ');
        $this->command->info('Demo properties: '.$codes.($created ? ' (new: '.implode(', ', $created).')' : ' (already present)'));

        if ($this->credentials === []) {
            $this->command->info('Demo users already exist; their passwords were shown when they were created (default from OZ_DEMO_PASSWORD).');

            return;
        }

        $this->command->warn('Demo sign-in details (shown once):');
        $this->command->table(['E-mail', 'Role', 'Password'], $this->credentials);
        if (config('ozepms.security.require_2fa.property_owners')) {
            $this->command->line('The owner account must set up two-step verification at first sign-in.');
        }
    }
}
