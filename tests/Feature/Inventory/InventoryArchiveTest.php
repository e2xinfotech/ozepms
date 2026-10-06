<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\InventoryArchiver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Retention of the daily tables: inventory:archive removes old nights only, in batches. */
class InventoryArchiveTest extends InventoryTestCase
{
    /** Writes one night of inventory, ARI and occupancy price for a room type / product, $offset days from today. */
    private function night(int $roomTypeId, int $productId, int $offset, ?int $propertyId = null): void
    {
        $propertyId ??= $this->property->id;
        $date = $this->day($offset)->toDateString();
        DB::table('inventory_daily')->updateOrInsert(['room_type_id' => $roomTypeId, 'stay_date' => $date], ['property_id' => $propertyId, 'total_units' => 2, 'updated_at' => now()]);
        DB::table('ari_daily')->updateOrInsert(['product_id' => $productId, 'stay_date' => $date], ['property_id' => $propertyId, 'price' => '100.00', 'updated_at' => now()]);
        DB::table('ari_daily_occupancy')->updateOrInsert(['product_id' => $productId, 'stay_date' => $date, 'adults' => 1], ['property_id' => $propertyId, 'price' => '90.00', 'updated_at' => now()]);
    }

    private function rows(string $table, int $offset, ?int $propertyId = null): int
    {
        return DB::table($table)->where('property_id', $propertyId ?? $this->property->id)->where('stay_date', $this->day($offset)->toDateString())->count();
    }

    public function test_old_nights_are_removed_and_recent_and_future_nights_kept(): void
    {
        config(['ozepms.inventory.retention_days' => 400, 'ozepms.inventory.archive_batch' => 100]);
        $rt = $this->makeRoomType(['code' => 'STD'], 2);
        $product = $this->product($rt, $this->bar());
        foreach ([-500, -401, -400, -30, 0, 10] as $offset) {
            $this->night($rt->id, $product->id, $offset);
        }
        // Many old rows: more than one batch.
        for ($i = 0; $i < 150; $i++) {
            $this->night($rt->id, $product->id, -600 - $i);
        }
        $theirs = $this->makeRoomType(['code' => 'THEIRS'], 1, $this->other);
        $theirProduct = $this->product($theirs, $this->bar($this->other));
        $this->night($theirs->id, $theirProduct->id, -500, $this->other->id);

        $this->artisan('inventory:archive', ['--property' => $this->property->code])->assertSuccessful();

        foreach (['inventory_daily', 'ari_daily', 'ari_daily_occupancy'] as $table) {
            $this->assertSame(0, $this->rows($table, -500), $table);
            $this->assertSame(0, $this->rows($table, -401), $table);
            $this->assertSame(0, $this->rows($table, -700), $table);
            foreach ([-400, -30, 0, 10] as $kept) {
                $this->assertSame(1, $this->rows($table, $kept), "$table $kept");
            }
            // Only the chosen property.
            $this->assertSame(1, $this->rows($table, -500, $this->other->id), $table);
        }
    }

    public function test_nights_of_open_reservations_are_kept(): void
    {
        $rt = $this->makeRoomType(['code' => 'STD'], 2);
        $product = $this->product($rt, $this->bar());
        $this->night($rt->id, $product->id, -450);
        $this->night($rt->id, $product->id, -500);
        // A booking that was never checked out, starting 450 days ago.
        $sourceId = DB::table('booking_sources')->insertGetId(['property_id' => null, 'code' => 'walk_in_'.Str::random(4), 'name' => 'Walk-in', 'is_active' => 1]);
        $reservationId = DB::table('reservations')->insertGetId([
            'public_id' => (string) Str::ulid(), 'property_id' => $this->property->id, 'booking_ref' => 'R-OLD1', 'status' => 'confirmed',
            'source_id' => $sourceId, 'guest_name' => 'Long Stay', 'check_in' => $this->day(-450)->toDateString(), 'check_out' => $this->day(-440)->toDateString(),
            'nights' => 10, 'adults' => 1, 'currency_code' => 'INR', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('reservation_rooms')->insert([
            'property_id' => $this->property->id, 'reservation_id' => $reservationId, 'room_type_id' => $rt->id, 'rate_plan_id' => $this->bar()->id,
            'product_id' => $product->id, 'status' => 'confirmed', 'check_in' => $this->day(-450)->toDateString(), 'check_out' => $this->day(-440)->toDateString(),
            'adults' => 1, 'rate_snapshot' => '{}', 'room_total' => '1000.00', 'grand_total' => '1000.00', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame($this->day(-450)->toDateString(), app(InventoryArchiver::class)->cutoff($this->property->id, 400)->toDateString());
        $this->artisan('inventory:archive')->assertSuccessful();

        $this->assertSame(1, $this->rows('ari_daily', -450));
        $this->assertSame(0, $this->rows('ari_daily', -500));
    }

    public function test_change_log_has_its_own_retention(): void
    {
        config(['ozepms.inventory.change_log_retention_days' => 90]);
        $row = ['property_id' => $this->property->id, 'scope' => 'rate', 'date_from' => $this->day(1)->toDateString(), 'date_to' => $this->day(1)->toDateString(),
            'weekdays' => 127, 'payload' => '{}', 'source' => 'system'];
        DB::table('ari_change_log')->insert([$row + ['created_at' => now()->subDays(120)], $row + ['created_at' => now()->subDays(10)]]);

        $this->artisan('inventory:archive')->assertSuccessful();

        $this->assertSame(1, DB::table('ari_change_log')->where('property_id', $this->property->id)->count());
        $this->assertTrue(DB::table('ari_change_log')->where('property_id', $this->property->id)->where('created_at', '>', now()->subDays(20))->exists());
    }

    public function test_dry_run_only_counts(): void
    {
        $rt = $this->makeRoomType(['code' => 'STD'], 2);
        $product = $this->product($rt, $this->bar());
        $this->night($rt->id, $product->id, -500);

        $this->artisan('inventory:archive', ['--dry-run' => true])
            ->expectsOutputToContain('would be removed: inventory_daily 1 · ari_daily 1 · ari_daily_occupancy 1')
            ->assertSuccessful();
        $this->assertSame(1, $this->rows('ari_daily', -500));

        $this->artisan('inventory:archive', ['--days' => 600])->assertSuccessful();
        $this->assertSame(1, $this->rows('ari_daily', -500));
    }

    public function test_retention_below_one_day_is_refused(): void
    {
        $this->artisan('inventory:archive', ['--days' => 0])->assertFailed();
        $this->expectException(\InvalidArgumentException::class);
        app(InventoryArchiver::class)->archive($this->property, 0);
    }

    public function test_archive_runs_monthly(): void
    {
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'inventory:archive'));

        $this->assertNotNull($event);
        $this->assertSame('30 1 1 * *', $event->expression);
    }
}
