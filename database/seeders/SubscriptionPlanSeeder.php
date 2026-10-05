<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            ['code' => 'starter', 'name' => 'Starter', 'description' => 'Small properties and homestays', 'price' => 1999, 'max_room_types' => 5, 'max_units' => 20, 'max_users' => 3,
                'features' => ['booking_engine' => false, 'channel_manager' => false, 'reports' => true, 'ai' => false], 'sort_order' => 1],
            ['code' => 'professional', 'name' => 'Professional', 'description' => 'Hotels and resorts', 'price' => 4999, 'max_room_types' => 20, 'max_units' => 150, 'max_users' => 15,
                'features' => ['booking_engine' => true, 'channel_manager' => true, 'reports' => true, 'ai' => false], 'sort_order' => 2],
            ['code' => 'enterprise', 'name' => 'Enterprise', 'description' => 'Large and multi-property groups', 'price' => 9999, 'max_room_types' => null, 'max_units' => null, 'max_users' => null,
                'features' => ['booking_engine' => true, 'channel_manager' => true, 'reports' => true, 'ai' => true], 'sort_order' => 3],
        ];

        foreach ($plans as $plan) {
            SubscriptionPlan::query()->updateOrCreate(['code' => $plan['code']], $plan + [
                'currency_code' => 'INR', 'billing_cycle' => 'monthly', 'trial_days' => 14, 'grace_days' => 7, 'is_active' => true,
            ]);
        }
    }
}
