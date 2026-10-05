<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Countries, states/provinces, currencies, languages and property types.
 * Source data lives in database/data/*.json. Safe to run repeatedly.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $currencies = $this->json('currencies.json');
        foreach (array_chunk($currencies, 200) as $chunk) {
            DB::table('currencies')->upsert($chunk, ['code'], ['name', 'symbol', 'minor_units']);
        }

        $known = array_column($currencies, 'code');
        $countries = array_map(function ($c) use ($known) {
            $c['currency_code'] = in_array($c['currency_code'], $known, true) ? $c['currency_code'] : null;

            return $c;
        }, $this->json('countries.json'));
        foreach (array_chunk($countries, 200) as $chunk) {
            DB::table('countries')->upsert($chunk, ['iso2'], ['iso3', 'name', 'phone_code', 'currency_code', 'default_timezone']);
        }

        foreach (array_chunk($this->json('states.json'), 500) as $chunk) {
            DB::table('states')->upsert($chunk, ['country_iso2', 'code'], ['name', 'tax_region_code']);
        }

        DB::table('languages')->upsert([
            ['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'is_active' => true, 'sort_order' => 1],
            ['code' => 'fr', 'name' => 'French', 'native_name' => 'Français', 'is_active' => true, 'sort_order' => 2],
            ['code' => 'it', 'name' => 'Italian', 'native_name' => 'Italiano', 'is_active' => true, 'sort_order' => 3],
            ['code' => 'de', 'name' => 'German', 'native_name' => 'Deutsch', 'is_active' => true, 'sort_order' => 4],
        ], ['code'], ['name', 'native_name', 'sort_order']);

        $types = ['hotel', 'resort', 'villa', 'apartment', 'homestay', 'boutique', 'heritage_hotel', 'hostel', 'individual_property', 'caravan', 'other'];
        foreach ($types as $i => $code) {
            DB::table('property_types')->upsert(
                [['code' => $code, 'label_key' => 'property_type.'.$code, 'sort_order' => $i + 1, 'is_active' => true]],
                ['code'],
                ['label_key', 'sort_order'],
            );
        }
    }

    private function json(string $file): array
    {
        return json_decode((string) file_get_contents(database_path('data/'.$file)), true, flags: JSON_THROW_ON_ERROR);
    }
}
