<?php

namespace Tests\Feature\Accommodation;

use App\Models\PhysicalUnit;
use App\Models\Product;
use App\Models\Property;
use App\Models\RatePlan;
use Database\Seeders\AccommodationDemoSeeder;
use Tests\TestCase;

class AccommodationDemoSeederTest extends TestCase
{
    public function test_runs_standalone_and_is_idempotent(): void
    {
        $this->seed(AccommodationDemoSeeder::class);
        $counts = [PhysicalUnit::acrossProperties()->count(), Product::acrossProperties()->count(), RatePlan::acrossProperties()->count()];

        $this->seed(AccommodationDemoSeeder::class);

        $this->assertSame(2, Property::query()->count());
        $this->assertSame([44, 18, 6], $counts);
        $this->assertSame($counts, [PhysicalUnit::acrossProperties()->count(), Product::acrossProperties()->count(), RatePlan::acrossProperties()->count()]);

        $nrf = Product::acrossProperties()->whereHas('ratePlan', fn ($q) => $q->where('code', 'NRF'))->firstOrFail();
        $this->assertSame('derived', $nrf->pricing_mode);
        $this->assertSame('-10.0000', $nrf->adjust_value);
    }
}
