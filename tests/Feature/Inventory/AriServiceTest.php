<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\AriChangeSet;
use App\Domain\Inventory\AriService;
use App\Models\Product;
use App\Models\RoomType;
use Illuminate\Support\Facades\DB;

/** Range and bulk edits of rates, restrictions and room-type availability. */
class AriServiceTest extends InventoryTestCase
{
    private RoomType $roomType;

    private Product $bar;

    private Product $nrf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->roomType = $this->makeRoomType([], 4);
        $this->bar = $this->product($this->roomType, $this->bar(), ['default_price' => '1000.00']);
        $this->nrf = $this->product($this->roomType, $this->plan('NRF'), [
            'pricing_mode' => 'derived', 'parent' => $this->bar, 'adjust_type' => 'percent', 'adjust_value' => '-10', 'inherit_restrictions' => true,
        ]);
    }

    public function test_price_range_with_weekday_filter(): void
    {
        $from = $this->day(7);
        $to = $from->addDays(13);
        $before = $this->version();

        $result = $this->apply([
            'date_from' => $from->toDateString(), 'date_to' => $to->toDateString(), 'weekdays' => [6, 7],
            'product_ids' => [$this->bar->id], 'price' => '1500',
        ]);

        $this->assertSame(4, $result['ari_rows'], 'two weekends');
        for ($d = $from; $d->lessThanOrEqualTo($to); $d = $d->addDay()) {
            $this->assertSame($d->dayOfWeekIso >= 6 ? '1500.00' : '1000.00', $this->ari($this->bar, $d)->price, $d->toDateString());
        }
        $this->assertSame($before + 1, $this->version());
        $this->assertSame($result['ari_version'], $this->version());

        $log = DB::table('ari_change_log')->where('product_id', $this->bar->id)->where('scope', 'rate')->where('source', 'system')->orderByDesc('id')->first();
        $this->assertSame(32 + 64, (int) $log->weekdays);
        $this->assertSame(['price' => '1500.00'], json_decode($log->payload, true));
        $this->assertSame($to->toDateString(), $log->date_to);
    }

    public function test_user_edits_are_logged_as_user_changes(): void
    {
        app(AriService::class)->apply(AriChangeSet::fromArray($this->property->id, [
            'date_from' => $this->day(1)->toDateString(), 'date_to' => $this->day(1)->toDateString(),
            'product_ids' => [$this->bar->id], 'min_los' => 2,
        ]), $this->owner);

        $log = DB::table('ari_change_log')->where('product_id', $this->bar->id)->where('scope', 'restriction')->orderByDesc('id')->first();
        $this->assertSame('user', $log->source);
        $this->assertSame($this->owner->id, (int) $log->user_id);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'ari.updated')->where('property_id', $this->property->id)->exists());
    }

    public function test_derived_price_edits_are_skipped_and_restrictions_follow_inheritance(): void
    {
        $result = $this->apply([
            'date_from' => $this->day(1)->toDateString(), 'date_to' => $this->day(3)->toDateString(),
            'product_ids' => [$this->bar->id, $this->nrf->id], 'price' => '1200', 'min_los' => 2, 'closed' => true,
        ]);

        $reasons = collect($result['skipped'])->where('id', $this->nrf->id)->pluck('reason')->all();
        $this->assertEqualsCanonicalizing(['derived_price', 'inherits_restrictions'], $reasons);
        $this->assertNull($this->ari($this->nrf, $this->day(2))->price);
        $this->assertNull($this->ari($this->nrf, $this->day(2))->min_los);
        $this->assertSame(1, (int) $this->ari($this->nrf, $this->day(2))->stop_sell, 'a derived product may always close itself');
        $this->assertSame('1200.00', $this->ari($this->bar, $this->day(2))->price);
        $this->assertSame(2, (int) $this->ari($this->bar, $this->day(2))->min_los);

        // A derived product with its own restrictions accepts them.
        Product::acrossProperties()->whereKey($this->nrf->id)->update(['inherit_restrictions' => false]);
        $result = $this->apply(['date_from' => $this->day(2)->toDateString(), 'date_to' => $this->day(2)->toDateString(), 'product_ids' => [$this->nrf->id], 'cta' => true, 'max_los' => 7]);
        $this->assertSame(1, $result['ari_rows']);
        $this->assertSame([1, 7], [(int) $this->ari($this->nrf, $this->day(2))->cta, (int) $this->ari($this->nrf, $this->day(2))->max_los]);
    }

    public function test_room_type_stop_sell_and_sell_limit(): void
    {
        $result = $this->apply([
            'date_from' => $this->day(0)->toDateString(), 'date_to' => $this->day(1)->toDateString(),
            'room_type_ids' => [$this->roomType->id], 'stop_sell' => true, 'sell_limit' => 10,
        ]);
        $this->assertSame(2, $result['inventory_rows']);
        $row = $this->inv($this->roomType, $this->day(1));
        $this->assertSame([1, 4], [(int) $row->stop_sell, (int) $row->sell_limit], 'limit capped at the 4 rooms');
        $this->assertSame('sell_limit_too_high', $result['skipped'][0]['reason']);

        $this->apply(['date_from' => $this->day(1)->toDateString(), 'date_to' => $this->day(1)->toDateString(), 'room_type_ids' => [$this->roomType->id], 'sell_limit' => 'none', 'stop_sell' => false]);
        $row = $this->inv($this->roomType, $this->day(1));
        $this->assertSame([0, null], [(int) $row->stop_sell, $row->sell_limit]);
    }

    public function test_stop_sell_targets_room_types_and_products(): void
    {
        $this->apply([
            'date_from' => $this->day(3)->toDateString(), 'date_to' => $this->day(3)->toDateString(),
            'room_type_ids' => [$this->roomType->id], 'product_ids' => [$this->bar->id], 'stop_sell' => true,
        ]);
        $this->assertSame(1, (int) $this->inv($this->roomType, $this->day(3))->stop_sell);
        $this->assertSame(1, (int) $this->ari($this->bar, $this->day(3))->stop_sell);
    }

    public function test_zero_removes_restrictions(): void
    {
        $range = ['date_from' => $this->day(1)->toDateString(), 'date_to' => $this->day(1)->toDateString(), 'product_ids' => [$this->bar->id]];
        $this->apply($range + ['min_los' => 3, 'min_advance' => 2, 'max_advance' => 90]);
        $this->apply($range + ['min_los' => 0, 'min_advance' => 0, 'max_advance' => 0]);
        $row = $this->ari($this->bar, $this->day(1));
        $this->assertSame([null, null, null], [$row->min_los, $row->cutoff_days, $row->max_advance_days]);
    }

    public function test_occupancy_prices_are_written_and_removed(): void
    {
        $range = ['date_from' => $this->day(1)->toDateString(), 'date_to' => $this->day(2)->toDateString(), 'product_ids' => [$this->bar->id, $this->nrf->id]];
        $result = $this->apply($range + ['occupancy_prices' => ['1' => '800', '3' => '1400', '9' => '5000']]);

        $this->assertSame(4, $result['occupancy_rows']);
        $this->assertSame('800.00', DB::table('ari_daily_occupancy')->where(['product_id' => $this->bar->id, 'adults' => 1])->value('price'));
        $this->assertSame(0, DB::table('ari_daily_occupancy')->where('product_id', $this->nrf->id)->count());
        $this->assertContains('occupancy_too_high', array_column($result['skipped'], 'reason'));

        $this->apply($range + ['occupancy_prices' => ['1' => null]]);
        $this->assertSame(2, DB::table('ari_daily_occupancy')->where('product_id', $this->bar->id)->count());
    }

    public function test_past_dates_and_other_property_ids_are_skipped(): void
    {
        $theirs = $this->makeRoomType([], 1, $this->other);
        $result = $this->apply([
            'date_from' => $this->day(-3)->toDateString(), 'date_to' => $this->day(1)->toDateString(),
            'room_type_ids' => [$this->roomType->id, $theirs->id], 'stop_sell' => true,
        ]);

        $this->assertSame(2, $result['inventory_rows']);
        $skipped = collect($result['skipped']);
        $this->assertSame('not_found', $skipped->firstWhere('id', $theirs->id)['reason']);
        $this->assertSame(3, $skipped->firstWhere('reason', 'past')['dates']);
        $this->assertSame(0, (int) $this->inv($theirs, $this->day(0))->stop_sell);
    }

    public function test_nothing_changed_keeps_the_version(): void
    {
        $range = ['date_from' => $this->day(1)->toDateString(), 'date_to' => $this->day(1)->toDateString(), 'product_ids' => [$this->bar->id]];
        $this->apply($range + ['price' => '1000']);
        $version = $this->version();
        $this->travel(5)->seconds();
        $result = $this->apply($range + ['price' => '1000']);
        $this->assertSame(0, $result['ari_rows']);
        $this->assertSame($version, $this->version());
    }

    public function test_edits_past_the_horizon_create_rows(): void
    {
        $result = $this->apply(['date_from' => $this->day(65)->toDateString(), 'date_to' => $this->day(66)->toDateString(), 'product_ids' => [$this->bar->id], 'price' => '900', 'room_type_ids' => [$this->roomType->id], 'stop_sell' => true]);
        $this->assertSame(2, $result['ari_rows']);
        $this->assertSame('900.00', $this->ari($this->bar, $this->day(66))->price);
        $this->assertSame(4, $this->inv($this->roomType, $this->day(66))->total_units);
    }
}
