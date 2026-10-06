<?php

namespace App\Console\Commands;

use App\Domain\Availability\AvailabilityService;
use App\Domain\Inventory\InventoryService;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Load test of the availability engine on a large data set.
 *
 *   DB_DATABASE=ozepms_perf php artisan migrate:fresh --seed --seeder="Database\Seeders\ReferenceDataSeeder"
 *   DB_DATABASE=ozepms_perf php artisan inventory:benchmark --seed
 *
 * --seed creates benchmark properties (codes BENCH0001 …) with room types, PMS rooms, rate plans
 * (the 4th derived from the 1st), occupancy rules and the full horizon of daily rows, plus some
 * sold rooms, weekend prices and restrictions. It refuses to run on a database whose name does
 * not contain "perf" or "bench", so the application data can never be touched.
 */
class BenchmarkAvailability extends Command
{
    protected $signature = 'inventory:benchmark
        {--seed : Create the benchmark data set first}
        {--properties=50} {--room-types=10} {--rate-plans=4} {--rooms=10} {--days=730}
        {--searches=500 : Searches to time}';

    protected $description = 'Time availability searches and inventory updates on a large data set';

    public function handle(AvailabilityService $availability, InventoryService $inventory): int
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');
        if ($this->option('seed')) {
            if (! preg_match('/perf|bench/i', $database)) {
                $this->error("Refusing to seed \"{$database}\": use a database whose name contains \"perf\" or \"bench\".");

                return self::FAILURE;
            }
            $this->seedData($inventory);
        }

        $properties = Property::query()->where('code', 'like', 'BENCH%')->get();
        if ($properties->isEmpty()) {
            $this->error('No benchmark properties found; run with --seed first.');

            return self::FAILURE;
        }

        $counts = [
            'inventory_daily' => DB::table('inventory_daily')->count(),
            'ari_daily' => DB::table('ari_daily')->count(),
            'room_type_rate_plans' => DB::table('room_type_rate_plans')->count(),
        ];
        $this->line('Database: '.$database.' · '.collect($counts)->map(fn ($n, $t) => "{$t} {$n}")->implode(' · '));

        $searches = max(10, (int) $this->option('searches'));
        $today = CarbonImmutable::parse(now('Asia/Kolkata')->toDateString());
        mt_srand(42);

        // Warm-up (connection, caches, opcode) is not measured.
        for ($i = 0; $i < 20; $i++) {
            $availability->search($properties->random(), $today->addDays(10), $today->addDays(12), 2, 0, 0);
        }

        $rows = [];
        foreach ([1, 3, 7, 14] as $nights) {
            $times = [];
            for ($i = 0; $i < $searches; $i++) {
                $property = $properties[mt_rand(0, $properties->count() - 1)];
                $in = $today->addDays(mt_rand(0, 700 - $nights));
                $adults = mt_rand(1, 3);
                $children = mt_rand(0, 1);
                $start = hrtime(true);
                $availability->search($property, $in, $in->addDays($nights), $adults, $children, 0);
                $times[] = (hrtime(true) - $start) / 1e6;
            }
            $rows[] = $this->stats("search, {$nights} night(s)", $times);
        }

        $times = [];
        $roomTypes = DB::table('room_types')->whereIn('property_id', $properties->pluck('id'))->pluck('id')->all();
        for ($i = 0; $i < min(200, $searches); $i++) {
            $rt = (int) $roomTypes[mt_rand(0, count($roomTypes) - 1)];
            $in = $today->addDays(mt_rand(0, 700));
            $start = hrtime(true);
            DB::beginTransaction();
            try {
                $inventory->reserve($rt, $in, $in->addDays(3));
            } catch (\App\Domain\Inventory\Exceptions\NotAvailableException) {
                // A full night is a normal outcome; it is timed as well.
            } finally {
                DB::rollBack();
            }
            $times[] = (hrtime(true) - $start) / 1e6;
        }
        $rows[] = $this->stats('reserve() 3 nights (rolled back)', $times);

        $this->table(['Operation', 'Runs', 'Mean ms', 'p50 ms', 'p95 ms', 'p99 ms', 'Max ms'], $rows);

        return self::SUCCESS;
    }

    /** @param  list<float>  $times */
    private function stats(string $label, array $times): array
    {
        sort($times);
        $pick = fn (float $p) => $times[(int) min(count($times) - 1, floor($p * count($times)))];

        return [$label, count($times), number_format(array_sum($times) / count($times), 2), number_format($pick(0.50), 2),
            number_format($pick(0.95), 2), number_format($pick(0.99), 2), number_format(end($times), 2)];
    }

    private function seedData(InventoryService $inventory): void
    {
        $count = (int) $this->option('properties');
        $roomTypes = (int) $this->option('room-types');
        $plans = max(2, (int) $this->option('rate-plans'));
        $rooms = (int) $this->option('rooms');
        $days = (int) $this->option('days');

        $typeId = DB::table('property_types')->value('id');
        $country = DB::table('countries')->where('iso2', 'IN')->exists() ? 'IN' : DB::table('countries')->value('iso2');
        $currency = DB::table('currencies')->where('code', 'INR')->exists() ? 'INR' : DB::table('currencies')->value('code');
        $mealPlan = DB::table('meal_plans')->whereNull('property_id')->value('id')
            ?? DB::table('meal_plans')->insertGetId(['property_id' => null, 'code' => 'RO', 'name' => 'Room only']);
        $now = now();
        $started = microtime(true);

        $bar = $this->output->createProgressBar($count);
        for ($p = 1; $p <= $count; $p++) {
            $code = sprintf('BENCH%04d', $p);
            if (DB::table('properties')->where('code', $code)->exists()) {
                $bar->advance();

                continue;
            }
            $propertyId = DB::table('properties')->insertGetId([
                'code' => $code, 'slug' => Str::lower($code), 'name' => "Benchmark Hotel {$p}", 'property_type_id' => $typeId,
                'status' => 'active', 'country_iso2' => $country, 'currency_code' => $currency, 'timezone' => 'Asia/Kolkata',
                'default_language' => 'en', 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('property_age_bands')->insert([
                ['property_id' => $propertyId, 'code' => 'infant', 'min_age' => 0, 'max_age' => 2],
                ['property_id' => $propertyId, 'code' => 'child', 'min_age' => 3, 'max_age' => 11],
            ]);
            $policyId = DB::table('cancellation_policies')->insertGetId([
                'property_id' => $propertyId, 'code' => 'FLEX', 'name' => 'Flexible', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $planIds = [];
            for ($r = 1; $r <= $plans; $r++) {
                $planIds[] = DB::table('rate_plans')->insertGetId([
                    'public_id' => (string) Str::ulid(), 'property_id' => $propertyId, 'code' => "RP{$r}", 'name' => "Rate plan {$r}",
                    'meal_plan_id' => $mealPlan, 'cancellation_policy_id' => $policyId, 'default_min_los' => 1,
                    'sort_order' => $r, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            for ($t = 1; $t <= $roomTypes; $t++) {
                $roomTypeId = DB::table('room_types')->insertGetId([
                    'public_id' => (string) Str::ulid(), 'property_id' => $propertyId, 'code' => "RT{$t}", 'name' => "Room type {$t}",
                    'base_adults' => 2, 'max_adults' => 3, 'max_children' => 2, 'max_infants' => 1, 'max_occupancy' => 4,
                    'sort_order' => $t, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $units = [];
                for ($u = 1; $u <= $rooms; $u++) {
                    $units[] = ['public_id' => (string) Str::ulid(), 'property_id' => $propertyId, 'room_type_id' => $roomTypeId,
                        'name' => sprintf('%d%02d', $t, $u), 'sort_order' => $u, 'created_at' => $now, 'updated_at' => $now];
                }
                DB::table('physical_units')->insert($units);

                $price = (string) (2000 + 500 * $t);
                $firstProduct = null;
                foreach ($planIds as $i => $planId) {
                    $derived = $i === count($planIds) - 1 && $firstProduct !== null;
                    $productId = DB::table('room_type_rate_plans')->insertGetId([
                        'public_id' => (string) Str::ulid(), 'property_id' => $propertyId, 'room_type_id' => $roomTypeId, 'rate_plan_id' => $planId,
                        'pricing_mode' => $derived ? 'derived' : 'manual', 'parent_product_id' => $derived ? $firstProduct : null,
                        'adjust_type' => $derived ? 'percent' : null, 'adjust_value' => $derived ? '-10' : null,
                        'default_price' => $derived ? null : $price + 300 * $i, 'is_default' => $i === 0 ? 1 : 0, 'sort_order' => $i,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $firstProduct ??= $productId;
                    if ($i === 0) {
                        DB::table('product_occupancy_rules')->insert([
                            ['product_id' => $productId, 'guest_type' => 'adult', 'guest_count' => 1, 'age_band_id' => null, 'adjust_type' => 'percent', 'adjust_value' => '-10'],
                            ['product_id' => $productId, 'guest_type' => 'adult', 'guest_count' => 3, 'age_band_id' => null, 'adjust_type' => 'fixed', 'adjust_value' => '500'],
                            ['product_id' => $productId, 'guest_type' => 'child', 'guest_count' => 1, 'age_band_id' => null, 'adjust_type' => 'fixed', 'adjust_value' => '250'],
                        ]);
                    }
                }
            }

            $property = Property::query()->findOrFail($propertyId);
            $inventory->ensureHorizon($property, $days);

            // Variety: weekend prices, some sold rooms, a few closed dates and minimum stays.
            DB::update('UPDATE ari_daily SET price = ROUND(price * 1.2, 2) WHERE property_id = ? AND price IS NOT NULL AND WEEKDAY(stay_date) IN (4, 5)', [$propertyId]);
            DB::update('UPDATE ari_daily SET min_los = 2 WHERE property_id = ? AND WEEKDAY(stay_date) = 5 AND MOD(DAYOFYEAR(stay_date), 3) = 0', [$propertyId]);
            DB::update('UPDATE ari_daily SET stop_sell = 1 WHERE property_id = ? AND MOD(DAYOFYEAR(stay_date) + product_id, 41) = 0', [$propertyId]);
            DB::update('UPDATE inventory_daily SET sold = FLOOR(RAND() * (total_units + 1)) WHERE property_id = ?', [$propertyId]);
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
        $this->info(sprintf('Seeded in %.1f s', microtime(true) - $started));
    }
}
