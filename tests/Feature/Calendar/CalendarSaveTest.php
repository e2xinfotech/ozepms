<?php

namespace Tests\Feature\Calendar;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/** Full path: calendar endpoint → AriService → daily tables → grid. */
class CalendarSaveTest extends CalendarTestCase
{
    private function grid(string $query = ''): array
    {
        return $this->actingAs($this->owner)->getJson($this->api('/calendar/grid?range=week&from='.$this->today().$query))->assertOk()->json('grid');
    }

    private function publicId(int $productId): string
    {
        return Product::acrossProperties()->findOrFail($productId)->public_id;
    }

    public function test_price_and_restrictions_saved_from_a_cell_show_on_the_grid(): void
    {
        $rt = $this->makeRoomType(['code' => 'STD', 'base_adults' => 2, 'max_adults' => 3], 2);
        $bar = $this->product($rt, $this->bar(), '1000.00');

        $this->actingAs($this->owner)->putJson($this->api('/calendar/cell'), [
            'date' => $this->day(2), 'product_id' => $this->publicId($bar), 'price' => '4500', 'min_los' => 2, 'cta' => true, 'occupancy_prices' => ['3' => '5200'],
        ])->assertOk()->assertJsonPath('result.skipped', []);

        $this->assertSame('4500.00', DB::table('ari_daily')->where('product_id', $bar)->where('stay_date', $this->day(2))->value('price'));
        $day = collect($this->grid()['room_types'][0]['products'])->firstWhere('id', $this->publicId($bar))['days'][2];
        $this->assertSame(['4500.00', 2, true, false], [$day['p'], $day['min'], $day['cta'], $day['d']]);
        $this->assertSame(['3' => '5200.00'], $day['o']);
        $this->assertDatabaseHas('ari_change_log', ['property_id' => $this->property->id, 'product_id' => $bar, 'source' => 'user', 'user_id' => $this->owner->id]);
    }

    public function test_room_type_stop_sell_and_sell_limit_over_a_range(): void
    {
        $rt = $this->makeRoomType(['code' => 'STD'], 3);

        $this->actingAs($this->owner)->putJson($this->api('/calendar/range'), [
            'date_from' => $this->day(1), 'date_to' => $this->day(3), 'room_type_ids' => [$rt->public_id], 'stop_sell' => true, 'sell_limit' => 2,
        ])->assertOk();

        $inv = $this->grid()['room_types'][0]['inventory'];
        $this->assertFalse($inv[0]['ss']);
        foreach ([1, 2, 3] as $i) {
            $this->assertTrue($inv[$i]['ss']);
            $this->assertSame(2, $inv[$i]['l']);
            $this->assertSame(2, $inv[$i]['a']);
        }
        $this->assertFalse($inv[4]['ss']);
    }

    public function test_bulk_weekend_minimum_stay(): void
    {
        $rt = $this->makeRoomType(['code' => 'STD'], 2);
        $bar = $this->product($rt, $this->bar(), '1000.00');

        $this->actingAs($this->owner)->postJson($this->api('/calendar/bulk'), [
            'date_from' => $this->day(0), 'date_to' => $this->day(13), 'weekdays' => [6, 7], 'rate_plan_ids' => [$this->bar()->public_id], 'min_los' => 3,
        ])->assertOk();

        $grid = $this->grid();
        $days = collect($grid['room_types'][0]['products'])->firstWhere('id', $this->publicId($bar))['days'];
        foreach ($grid['days'] as $i => $d) {
            $this->assertSame($d['dow'] >= 6 ? 3 : 1, $days[$i]['min'], $d['date']);
        }
    }

    public function test_derived_prices_are_not_overwritten_and_the_reason_is_returned(): void
    {
        $rt = $this->makeRoomType(['code' => 'STD'], 2);
        $bar = $this->product($rt, $this->bar(), '1000.00');
        $nrf = $this->product($rt, $this->ratePlan('NRF'), null, $bar, 'percent', '-10');

        $this->actingAs($this->owner)->putJson($this->api('/calendar/cell'), ['date' => $this->day(1), 'product_id' => $this->publicId($nrf), 'price' => '1'])
            ->assertOk()->assertJsonPath('result.changed', 0)->assertJsonPath('result.skipped.0.reason', 'derived_price');

        $this->actingAs($this->owner)->putJson($this->api('/calendar/cell'), ['date' => $this->day(1), 'product_id' => $this->publicId($bar), 'price' => '2000'])->assertOk();
        $day = collect($this->grid()['room_types'][0]['products'])->firstWhere('id', $this->publicId($nrf))['days'][1];
        $this->assertSame('1800.00', $day['p']);
    }

    public function test_past_dates_are_skipped(): void
    {
        $rt = $this->makeRoomType(['code' => 'STD'], 2);

        $this->actingAs($this->owner)->putJson($this->api('/calendar/range'), [
            'date_from' => $this->day(-2), 'date_to' => $this->day(0), 'room_type_ids' => [$rt->public_id], 'stop_sell' => true,
        ])->assertOk()->assertJsonPath('result.skipped.0.reason', 'past');
    }
}
