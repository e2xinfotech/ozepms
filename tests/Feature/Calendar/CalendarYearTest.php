<?php

namespace Tests\Feature\Calendar;

use Illuminate\Support\Facades\DB;

/** Year overview: twelve months per room type, availability level and lowest price per night. */
class CalendarYearTest extends CalendarTestCase
{
    private function year(string $query = ''): array
    {
        return $this->actingAs($this->owner)->getJson($this->api('/calendar/year?from='.substr($this->today(), 0, 7).$query))->assertOk()->json('year');
    }

    /** Index of a date in the year that starts on the 1st of this month. */
    private function at(string $date): int
    {
        return (int) ((strtotime($date) - strtotime(substr($this->today(), 0, 7).'-01')) / 86400);
    }

    public function test_twelve_months_with_levels_and_lowest_prices(): void
    {
        config(['ozepms.inventory.low_availability_percent' => 20]);
        $rt = $this->makeRoomType(['code' => 'STD', 'base_adults' => 2], 5);
        $bar = $this->product($rt, $this->bar(), '1000.00');
        $nrf = $this->product($rt, $this->ratePlan('NRF'), null, $bar, 'percent', '-10');
        $bb = $this->product($rt, $this->ratePlan('BB'), '1200.00');

        $this->inventory($rt, $this->day(1), ['total_units' => 5, 'sold' => 5]);                 // sold out
        $this->inventory($rt, $this->day(2), ['total_units' => 5, 'sold' => 4]);                 // 1 left of 5 → low
        $this->inventory($rt, $this->day(3), ['total_units' => 5, 'sold' => 0, 'stop_sell' => 1]);
        $this->ari($bar, $this->day(1), ['price' => '2000.00']);
        $this->ari($bar, $this->day(4), ['price' => '2000.00', 'stop_sell' => 1]);               // BAR closed; NRF follows (inherits)
        $this->ari($bb, $this->day(4), ['price' => '1500.00']);

        $year = $this->year();

        $this->assertCount(12, $year['months']);
        $this->assertSame(substr($this->today(), 0, 7), $year['months'][0]['month']);
        $days = array_sum(array_column($year['months'], 'days'));
        $row = $year['room_types'][0];
        $this->assertSame('STD', $row['code']);
        $this->assertSame($days, strlen($row['levels']));
        $this->assertCount($days, $row['prices']);

        $this->assertSame('0', $row['levels'][$this->at($this->day(1))]);
        $this->assertSame('1', $row['levels'][$this->at($this->day(2))]);
        $this->assertSame('s', $row['levels'][$this->at($this->day(3))]);
        $this->assertSame('2', $row['levels'][$this->at($this->day(5))]);
        // Lowest open price: the derived rate plan (BAR − 10 %).
        $this->assertSame('1800', $row['prices'][$this->at($this->day(1))]);
        // BAR closed and NRF follows it: only BB is open.
        $this->assertSame('1500', $row['prices'][$this->at($this->day(4))]);
        $this->assertSame('1500', collect($row['month_min'])->filter()->sortBy(fn ($v) => (float) $v)->first());
        // Nights before today have no stored rows.
        if ($this->at($this->today()) > 0) {
            $this->assertSame('.', $row['levels'][0]);
        }
    }

    public function test_rate_plan_filter_limits_prices_and_room_type_filter_limits_rows(): void
    {
        $std = $this->makeRoomType(['code' => 'STD'], 2);
        $dlx = $this->makeRoomType(['code' => 'DLX'], 2);
        $bar = $this->product($std, $this->bar(), '1000.00');
        $bb = $this->product($std, $this->ratePlan('BB'), '700.00');
        $this->product($dlx, $this->bar(), '3000.00');
        $this->ari($bar, $this->day(1), ['price' => '1000.00']);
        $this->ari($bb, $this->day(1), ['price' => '700.00']);

        $all = $this->year();
        $this->assertSame(['STD', 'DLX'], array_column($all['room_types'], 'code'));
        $this->assertSame('700', $all['room_types'][0]['prices'][$this->at($this->day(1))]);

        $barOnly = $this->year('&rate_plan='.$this->bar()->public_id);
        $this->assertSame('1000', $barOnly['room_types'][0]['prices'][$this->at($this->day(1))]);

        $one = $this->year('&room_type='.$dlx->public_id);
        $this->assertSame(['DLX'], array_column($one['room_types'], 'code'));

        DB::table('room_types')->where('id', $std->id)->update(['is_active' => 0]);
        $this->assertSame(['DLX'], array_column($this->year()['room_types'], 'code'));
        $this->assertSame(['STD'], array_column($this->year('&status=inactive')['room_types'], 'code'));
    }

    public function test_payload_is_compact(): void
    {
        $rt = $this->makeRoomType(['code' => 'STD'], 2);
        foreach (['BAR', 'BB', 'HB', 'FB'] as $code) {
            $this->product($rt, $code === 'BAR' ? $this->bar() : $this->ratePlan($code), '1000.00');
        }
        $json = $this->actingAs($this->owner)->getJson($this->api('/calendar/year'))->assertOk()->getContent();

        // One level string and one price list per room type, whatever the number of rate plans.
        $this->assertLessThan(8000, strlen($json));
    }

    public function test_validation_permission_and_other_property(): void
    {
        $this->actingAs($this->owner)->getJson($this->api('/calendar/year?from=2026-13'))->assertOk();   // an impossible month rolls over instead of failing the page
        $this->actingAs($this->owner)->getJson($this->api('/calendar/year?from=yesterday'))->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['from']]]);
        $this->actingAs($this->owner)->getJson($this->api('/calendar/year?status=bogus'))->assertStatus(422);
        $this->actingAs($this->member('housekeeping'))->getJson($this->api('/calendar/year'))->assertForbidden();
        $this->actingAs($this->member('front_desk'))->getJson($this->api('/calendar/year'))->assertOk();
        $this->actingAs($this->owner)->getJson('/web-api/p/'.$this->other->code.'/calendar/year')->assertNotFound();
    }

    public function test_other_property_rows_never_appear(): void
    {
        $this->makeRoomType(['code' => 'MINE'], 1);
        $theirs = $this->makeRoomType(['code' => 'THEIRS'], 1, $this->other);
        $this->ari($this->product($theirs, $this->bar($this->other)), $this->day(1), ['price' => '1.00']);

        $this->assertSame(['MINE'], array_column($this->year()['room_types'], 'code'));
        $this->assertSame([], $this->year('&room_type='.$theirs->public_id)['room_types']);
    }

    public function test_page_renders_the_year_view(): void
    {
        $this->makeRoomType(['code' => 'STD', 'name' => 'Standard Room'], 2);

        $html = $this->actingAs($this->owner)->get($this->page('/calendar?range=year'))->assertOk()->getContent();
        $this->assertStringContainsString('"range":"year"', $html);
        $this->assertStringContainsString('"levels":"', $html);
        $this->assertStringContainsString('"grid":null', $html);
    }
}
