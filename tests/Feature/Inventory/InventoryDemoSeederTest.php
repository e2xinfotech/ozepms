<?php

namespace Tests\Feature\Inventory;

use App\Domain\Availability\AvailabilityService;
use App\Domain\Inventory\InventoryService;
use App\Models\Property;
use Database\Seeders\AccommodationDemoSeeder;
use Database\Seeders\InventoryDemoSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryDemoSeederTest extends TestCase
{
    public function test_demo_properties_get_a_year_of_varied_rates_and_the_seeder_is_idempotent(): void
    {
        config(['ozepms.inventory.horizon_days' => 30]);
        // Creates the demo properties through DemoSeeder, which runs the inventory demo seeder too.
        $this->seed(AccommodationDemoSeeder::class);
        $property = \Database\Seeders\AccommodationDemoSeeder::demoProperties()->first();
        $this->assertNotNull($property);
        $today = app(InventoryService::class)->today($property->id);

        $roomTypeId = (int) DB::table('room_types')->where('property_id', $property->id)->orderBy('sort_order')->value('id');
        $this->assertSame(365, DB::table('inventory_daily')->where('room_type_id', $roomTypeId)->where('stay_date', '>=', $today->toDateString())->count(), 'a year from today (demo stays that began earlier add past rows)');

        $bar = DB::table('room_type_rate_plans as p')->join('rate_plans as rp', 'rp.id', '=', 'p.rate_plan_id')
            ->where('p.room_type_id', $roomTypeId)->where('rp.code', 'BAR')->first(['p.id', 'p.default_price']);
        $prices = DB::table('ari_daily')->where('product_id', $bar->id)
            ->whereBetween('stay_date', [$today->toDateString(), $today->addDays(13)->toDateString()])->pluck('price', 'stay_date');
        foreach ($prices as $date => $price) {
            $weekend = in_array(\Carbon\CarbonImmutable::parse($date)->dayOfWeekIso, [5, 6], true);
            $this->assertSame($weekend ? bcmul($bar->default_price, '1.2', 2) : $bar->default_price, $price, $date);
        }
        $this->assertSame(1, (int) DB::table('inventory_daily')->where('room_type_id', $roomTypeId)->where('stop_sell', 1)->count());
        $this->assertSame(2, DB::table('ari_daily')->where('product_id', $bar->id)->where('min_los', 2)->count());
        $this->assertSame(1, DB::table('ari_daily')->where('product_id', $bar->id)->where('cta', 1)->count());

        $version = (int) $property->fresh()->ari_version;
        $this->seed(InventoryDemoSeeder::class);
        $this->assertSame($version, (int) $property->fresh()->ari_version, 'second run changes nothing');

        $result = app(AvailabilityService::class)->search($property->fresh(), $today->addDays(3), $today->addDays(5), 2, 0, 0);
        $this->assertNotEmpty(collect($result->roomTypes)->flatMap(fn ($rt) => $rt['products'])->where('sellable', true));
    }
}
