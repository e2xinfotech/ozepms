<?php

namespace Tests\Feature\Inventory;

use App\Domain\Availability\AvailabilityService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Pricing\Exceptions\NoRateException;
use App\Domain\Pricing\PricingService;
use App\Models\Product;
use App\Models\RoomType;
use App\Support\PropertyContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** End-to-end search over real rows: rooms left, restrictions, prices, channels, tenancy. */
class AvailabilityServiceTest extends InventoryTestCase
{
    private RoomType $deluxe;

    private RoomType $suite;

    private Product $bar;

    private Product $nrf;

    private Product $suiteBar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->deluxe = $this->makeRoomType(['code' => 'DLX', 'max_adults' => 3, 'max_children' => 1, 'max_occupancy' => 3], 2);
        $this->suite = $this->makeRoomType(['code' => 'STE'], 1);
        $this->bar = $this->product($this->deluxe, $this->bar(), ['default_price' => '1000.00', 'occupancy_rules' => [
            ['guest_type' => 'adult', 'guest_count' => 3, 'adjust_type' => 'fixed', 'adjust_value' => '300'],
        ]]);
        $this->nrf = $this->product($this->deluxe, $this->plan('NRF'), ['pricing_mode' => 'derived', 'parent' => $this->bar, 'adjust_type' => 'percent', 'adjust_value' => '-10']);
        $this->suiteBar = $this->product($this->suite, $this->bar(), ['default_price' => '3000.00']);
    }

    private function search(int $in, int $out, int $adults = 2, int $children = 0, int $infants = 0, string $channel = 'pms'): array
    {
        return app(AvailabilityService::class)
            ->search($this->property->fresh(), $this->day($in), $this->day($out), $adults, $children, $infants, $channel)
            ->toArray();
    }

    private function entry(array $result, RoomType $roomType, ?Product $product = null): ?array
    {
        $rt = collect($result['room_types'])->firstWhere('room_type_id', $roomType->id);
        $this->assertNotNull($rt);

        return $product ? collect($rt['products'])->firstWhere('product_id', $product->id) : $rt;
    }

    public function test_open_dates_are_sellable_with_prices(): void
    {
        $result = $this->search(3, 6);

        $this->assertSame(3, $result['nights']);
        $this->assertSame(2, $this->entry($result, $this->deluxe)['available_units']);
        $bar = $this->entry($result, $this->deluxe, $this->bar);
        $this->assertTrue($bar['sellable']);
        $this->assertSame([], $bar['reasons']);
        $this->assertSame('3000.00', $bar['total']);
        $this->assertCount(3, $bar['nightly']);
        $this->assertSame(['date' => $this->day(3)->toDateString(), 'price' => '1000.00'], $bar['nightly'][0]);
        $this->assertSame('2700.00', $this->entry($result, $this->deluxe, $this->nrf)['total']);
        $this->assertSame('9000.00', $this->entry($result, $this->suite, $this->suiteBar)['total']);
        $this->assertSame($this->bar->public_id, $bar['product']['id']);
        $this->assertSame('BAR', $bar['rate_plan']['code']);
        $this->assertSame('3900.00', $this->entry($this->search(3, 6, 3), $this->deluxe, $this->bar)['total'], 'extra adult');
    }

    public function test_sold_out_and_out_of_order_nights(): void
    {
        $inventory = app(InventoryService::class);
        $inventory->reserve($this->deluxe->id, $this->day(4), $this->day(5), 1);
        $this->assertSame(1, $this->entry($this->search(3, 6), $this->deluxe)['available_units']);

        $inventory->blockUnits($this->deluxe->id, $this->day(5), $this->day(6), 1);
        $inventory->reserve($this->deluxe->id, $this->day(5), $this->day(6), 1);
        $result = $this->search(3, 6);
        $this->assertSame(0, $this->entry($result, $this->deluxe)['available_units']);
        $this->assertSame(['sold_out'], $this->entry($result, $this->deluxe, $this->bar)['reasons']);
        $this->assertFalse($this->entry($result, $this->deluxe, $this->nrf)['sellable']);
        $this->assertNotNull($this->entry($result, $this->deluxe, $this->bar)['total'], 'price still shown');
        $this->assertTrue($this->entry($result, $this->suite, $this->suiteBar)['sellable']);
    }

    public function test_restrictions_close_products(): void
    {
        $date = fn (int $d) => $this->day($d)->toDateString();
        $this->apply(['date_from' => $date(3), 'date_to' => $date(3), 'product_ids' => [$this->bar->id], 'cta' => true]);
        $this->apply(['date_from' => $date(6), 'date_to' => $date(6), 'product_ids' => [$this->bar->id], 'ctd' => true]);
        $this->apply(['date_from' => $date(10), 'date_to' => $date(11), 'product_ids' => [$this->bar->id], 'min_los' => 3]);
        $this->apply(['date_from' => $date(20), 'date_to' => $date(20), 'room_type_ids' => [$this->deluxe->id], 'stop_sell' => true]);
        $this->apply(['date_from' => $date(0), 'date_to' => $date(1), 'product_ids' => [$this->bar->id], 'min_advance' => 2]);

        $this->assertSame(['cta', 'ctd'], $this->entry($this->search(3, 6), $this->deluxe, $this->bar)['reasons']);
        $this->assertSame(['cta', 'ctd'], $this->entry($this->search(3, 6), $this->deluxe, $this->nrf)['reasons'], 'derived product inherits');
        $this->assertSame(['min_los'], $this->entry($this->search(10, 12), $this->deluxe, $this->bar)['reasons']);
        $this->assertSame([], $this->entry($this->search(10, 13), $this->deluxe, $this->bar)['reasons']);
        $this->assertSame(['stop_sell'], $this->entry($this->search(19, 22), $this->deluxe, $this->bar)['reasons']);
        $this->assertSame(['cutoff'], $this->entry($this->search(1, 2), $this->deluxe, $this->bar)['reasons']);
        $this->assertSame([], $this->entry($this->search(2, 3), $this->deluxe, $this->bar)['reasons']);

        $this->apply(['date_from' => $date(30), 'date_to' => $date(30), 'product_ids' => [$this->nrf->id], 'closed' => true]);
        $result = $this->search(30, 31);
        $this->assertSame(['closed'], $this->entry($result, $this->deluxe, $this->nrf)['reasons']);
        $this->assertTrue($this->entry($result, $this->deluxe, $this->bar)['sellable']);
    }

    public function test_occupancy_limits(): void
    {
        $result = $this->search(3, 4, 2, 2);
        $this->assertFalse($this->entry($result, $this->deluxe)['occupancy_ok']);
        $this->assertSame(['occupancy'], $this->entry($result, $this->deluxe, $this->bar)['reasons']);
        $this->assertNull($this->entry($result, $this->deluxe, $this->bar)['total']);
        $this->assertTrue($this->entry($result, $this->suite, $this->suiteBar)['sellable']);
        $this->assertFalse($this->entry($this->search(3, 4, 4), $this->deluxe)['occupancy_ok']);
        $this->assertFalse($this->entry($this->search(3, 4, 0), $this->deluxe)['occupancy_ok']);
    }

    public function test_dates_without_rows_have_no_inventory(): void
    {
        $result = $this->search(59, 62);
        $this->assertSame(0, $this->entry($result, $this->deluxe)['available_units']);
        $this->assertContains('no_inventory', $this->entry($result, $this->deluxe, $this->bar)['reasons']);
    }

    public function test_channel_flags_and_inactive_products(): void
    {
        DB::table('rate_plans')->where('id', $this->nrf->rate_plan_id)->update(['sell_on_booking_engine' => 0]);
        $this->assertNull($this->entry($this->search(3, 4, channel: 'booking_engine'), $this->deluxe, $this->nrf));
        $this->assertNotNull($this->entry($this->search(3, 4), $this->deluxe, $this->nrf));

        Product::acrossProperties()->whereKey($this->nrf->id)->update(['is_active' => false]);
        $this->assertNull($this->entry($this->search(3, 4), $this->deluxe, $this->nrf));

        RoomType::acrossProperties()->whereKey($this->suite->id)->update(['is_active' => false]);
        $this->assertNull(collect($this->search(3, 4)['room_types'])->firstWhere('room_type_id', $this->suite->id));
    }

    public function test_search_only_sees_its_property_whatever_the_selected_property(): void
    {
        $this->makeRoomType([], 1, $this->other);
        $this->inProperty($this->other);
        $ids = collect($this->search(3, 4)['room_types'])->pluck('room_type_id')->sort()->values()->all();
        app(PropertyContext::class)->clear();

        $this->assertSame(collect([$this->deluxe->id, $this->suite->id])->sort()->values()->all(), $ids);
    }

    public function test_search_uses_a_fixed_number_of_queries(): void
    {
        $this->search(3, 4); // warm the currency cache
        DB::enableQueryLog();
        $this->search(3, 10);
        $short = count(DB::getQueryLog());
        DB::flushQueryLog();
        $this->search(3, 40, 2, 1, 1);
        $long = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($short, $long, 'query count does not grow with nights or products');
        // property reload in the helper + room types, products, rules, rate plans, inventory, ARI, occupancy prices, age bands
        $this->assertLessThanOrEqual(9, $long);
    }

    public function test_invalid_stay_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->search(5, 5);
    }

    public function test_pricing_quote_reads_its_own_rows(): void
    {
        $quote = app(PricingService::class)->quote($this->nrf->fresh(), $this->day(3), $this->day(5), 3, []);
        $this->assertSame('2340.00', $quote->roomTotal, '(1000 + 300) × 0.9 × 2');
        $this->assertSame($this->nrf->id, $quote->productId);

        DB::table('ari_daily')->where('product_id', $this->bar->id)->where('stay_date', $this->day(4)->toDateString())->update(['price' => null]);
        try {
            app(PricingService::class)->quote($this->nrf->fresh(), $this->day(3), $this->day(5), 2, []);
            $this->fail('Expected NoRateException');
        } catch (NoRateException $e) {
            $this->assertSame([$this->day(4)->toDateString()], $e->dates);
        }
        $this->assertContains('no_rate', $this->entry($this->search(3, 5), $this->deluxe, $this->bar)['reasons']);
    }

    public function test_result_can_be_serialised(): void
    {
        $json = json_encode($this->search(3, 4));
        $this->assertIsString($json);
        $this->assertStringContainsString('"room_total":"1000.00"', $json);
        $this->assertInstanceOf(CarbonImmutable::class, $this->day(0));
    }
}
