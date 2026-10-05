<?php

namespace Tests\Feature\Property;

use App\Models\Property;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    public function test_demo_data_is_created_once(): void
    {
        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class);

        $this->assertSame(2, Property::query()->whereIn('slug', ['sunrise-grand-hotel', 'ocean-view-resort'])->count());
        foreach (['owner', 'manager', 'frontdesk'] as $who) {
            $user = User::query()->where('email', "{$who}@demo.ozepms.test")->firstOrFail();
            $this->assertTrue(Hash::check(config('ozepms.demo.password'), $user->password));
        }

        $sunrise = Property::query()->where('slug', 'sunrise-grand-hotel')->firstOrFail();
        $this->assertSame(3, $sunrise->members()->count());
        $this->assertSame('active', $sunrise->currentSubscription->status);

        $manager = User::query()->where('email', 'manager@demo.ozepms.test')->firstOrFail();
        $this->actingAs($manager)->get("/p/{$sunrise->code}/dashboard")->assertOk();
    }
}
