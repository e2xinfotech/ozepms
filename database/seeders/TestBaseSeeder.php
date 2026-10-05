<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Minimal reference data for automated tests (fast: a few countries only). */
class TestBaseSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('currencies')->insert([
            ['code' => 'INR', 'name' => 'Indian Rupee', 'symbol' => '₹', 'minor_units' => 2, 'is_active' => true],
            ['code' => 'AED', 'name' => 'UAE Dirham', 'symbol' => 'AED', 'minor_units' => 2, 'is_active' => true],
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'minor_units' => 2, 'is_active' => true],
            ['code' => 'JPY', 'name' => 'Japanese Yen', 'symbol' => '¥', 'minor_units' => 0, 'is_active' => true],
        ]);
        DB::table('countries')->insert([
            ['iso2' => 'IN', 'iso3' => 'IND', 'name' => 'India', 'phone_code' => '+91', 'currency_code' => 'INR', 'default_timezone' => 'Asia/Kolkata', 'is_active' => true],
            ['iso2' => 'AE', 'iso3' => 'ARE', 'name' => 'United Arab Emirates', 'phone_code' => '+971', 'currency_code' => 'AED', 'default_timezone' => 'Asia/Dubai', 'is_active' => true],
            ['iso2' => 'FR', 'iso3' => 'FRA', 'name' => 'France', 'phone_code' => '+33', 'currency_code' => 'EUR', 'default_timezone' => 'Europe/Paris', 'is_active' => true],
        ]);
        DB::table('states')->insert([
            ['country_iso2' => 'IN', 'code' => 'IN-MH', 'name' => 'Maharashtra', 'tax_region_code' => '27'],
            ['country_iso2' => 'IN', 'code' => 'IN-KA', 'name' => 'Karnataka', 'tax_region_code' => '29'],
            ['country_iso2' => 'AE', 'code' => 'AE-DU', 'name' => 'Dubai', 'tax_region_code' => null],
        ]);
        DB::table('languages')->insert([
            ['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'is_active' => true, 'sort_order' => 1],
            ['code' => 'fr', 'name' => 'French', 'native_name' => 'Français', 'is_active' => true, 'sort_order' => 2],
            ['code' => 'it', 'name' => 'Italian', 'native_name' => 'Italiano', 'is_active' => true, 'sort_order' => 3],
            ['code' => 'de', 'name' => 'German', 'native_name' => 'Deutsch', 'is_active' => true, 'sort_order' => 4],
        ]);
        foreach (['hotel', 'resort', 'villa', 'apartment', 'homestay', 'other'] as $i => $code) {
            DB::table('property_types')->insert(['code' => $code, 'label_key' => 'property_type.'.$code, 'sort_order' => $i, 'is_active' => true]);
        }

        $this->call([PermissionSeeder::class, SubscriptionPlanSeeder::class]);
    }
}
