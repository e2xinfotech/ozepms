<?php

namespace Database\Seeders;

use App\Domain\Accommodation\InProperty;
use App\Domain\Inventory\AriChangeSet;
use App\Domain\Inventory\AriService;
use App\Domain\Inventory\InventoryService;
use App\Models\Property;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo rates and restrictions for the demo properties (P1001, P1002), on top of the rooms and
 * rate plans from AccommodationDemoSeeder: one year of daily rows with weekend prices (+20 %),
 * a high season (+30 %), a stop-sell day, a minimum-stay weekend and a closed-to-arrival day.
 * Idempotent: values are derived from the products' default prices, so a second run changes nothing.
 */
class InventoryDemoSeeder extends Seeder
{
    private const CODES = ['P1001', 'P1002'];

    private const DAYS = 365;

    public function run(): void
    {
        $properties = Property::query()->whereIn('code', self::CODES)->orderBy('id')->get();
        if ($properties->isEmpty()) {
            $this->command?->warn('No demo properties found; run the DemoSeeder first.');

            return;
        }

        foreach ($properties as $property) {
            InProperty::run($property, fn () => $this->seedProperty($property));
            $this->command?->info("Rates and restrictions ready for {$property->code}.");
        }
    }

    private function seedProperty(Property $property): void
    {
        $inventory = app(InventoryService::class);
        $inventory->ensureHorizon($property, max(self::DAYS, (int) config('ozepms.inventory.horizon_days')));
        $today = $inventory->today($property->id);
        $end = $today->addDays(self::DAYS - 1);

        $roomTypes = DB::table('room_types')->where('property_id', $property->id)->whereNull('deleted_at')
            ->orderBy('sort_order')->pluck('id', 'code');
        $manual = DB::table('room_type_rate_plans as p')->join('rate_plans as rp', 'rp.id', '=', 'p.rate_plan_id')
            ->where('p.property_id', $property->id)->where('p.pricing_mode', 'manual')->whereNotNull('p.default_price')
            ->get(['p.id', 'p.room_type_id', 'p.default_price', 'rp.code as plan']);
        if ($roomTypes->isEmpty() || $manual->isEmpty()) {
            return;
        }

        $season = [$today->addDays(60), $today->addDays(74)];
        foreach ($manual as $product) {
            $price = (string) $product->default_price;
            // Friday and Saturday nights outside the season, then the high season (every day).
            $weekend = ['product_ids' => [$product->id], 'price' => Money::round(Money::mul($price, '1.2'), 0), 'weekdays' => [5, 6]];
            $this->apply($property, $today, $season[0]->subDay(), $weekend);
            $this->apply($property, $season[1]->addDay(), $end, $weekend);
            $this->apply($property, $season[0], $season[1], ['product_ids' => [$product->id], 'price' => Money::round(Money::mul($price, '1.3'), 0)]);
        }

        // A sold-out style stop sell on the first room type in two weeks.
        $this->apply($property, $today->addDays(14), $today->addDays(14), ['room_type_ids' => [$roomTypes->first()], 'stop_sell' => true]);

        // Minimum two nights on the weekend about three weeks ahead, for the main (BAR) rate plans.
        $friday = $today->addDays(21);
        while ($friday->dayOfWeekIso !== 5) {
            $friday = $friday->addDay();
        }
        $bar = $manual->where('plan', 'BAR')->pluck('id')->all() ?: $manual->pluck('id')->all();
        $this->apply($property, $friday, $friday->addDay(), ['product_ids' => $bar, 'min_los' => 2]);

        // No arrivals on the first day of the high season.
        $this->apply($property, $season[0], $season[0], ['product_ids' => $bar, 'cta' => true]);
    }

    private function apply(Property $property, CarbonImmutable $from, CarbonImmutable $to, array $values): void
    {
        app(AriService::class)->apply(AriChangeSet::fromArray($property->id, array_merge([
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
        ], $values)));
    }
}
