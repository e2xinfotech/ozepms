<?php

namespace Tests\Feature\Calendar;

use App\Domain\Inventory\AvailabilityLevel;
use App\Domain\Inventory\Queries\CalendarQuery;
use Illuminate\Support\Facades\DB;

/** Calendar filters from the spec: availability, price, restrictions, active / inactive (server-side, query string). */
class CalendarFiltersTest extends CalendarTestCase
{
    private function codes(string $query): array
    {
        $grid = $this->actingAs($this->owner)->getJson($this->api('/calendar/grid?range=week&from='.$this->today().$query))->assertOk()->json('grid');

        return array_map(fn ($rt) => $rt['code'].':'.implode(',', array_map(fn ($p) => $p['rate_plan']['code'], $rt['products'])), $grid['room_types']);
    }

    public function test_low_availability_rule(): void
    {
        config(['ozepms.inventory.low_availability_percent' => 20]);
        $this->assertSame(AvailabilityLevel::SOLD_OUT, AvailabilityLevel::of(0, 10));
        $this->assertSame(AvailabilityLevel::LOW, AvailabilityLevel::of(2, 10));
        $this->assertSame(AvailabilityLevel::OK, AvailabilityLevel::of(3, 10));
        $this->assertSame(AvailabilityLevel::LOW, AvailabilityLevel::of(1, 3));   // at least one room counts as low
        $this->assertSame(AvailabilityLevel::OK, AvailabilityLevel::of(2, 3));
    }

    public function test_availability_filter(): void
    {
        $full = $this->makeRoomType(['code' => 'FULL'], 5);
        $low = $this->makeRoomType(['code' => 'LOW'], 5);
        $open = $this->makeRoomType(['code' => 'OPEN'], 5);
        foreach ([$full, $low, $open] as $rt) {
            $this->product($rt, $this->bar());
        }
        $this->inventory($full, $this->day(2), ['total_units' => 5, 'sold' => 5]);
        $this->inventory($low, $this->day(3), ['total_units' => 5, 'sold' => 4]);

        $this->assertSame(['FULL:BAR'], $this->codes('&availability=sold_out'));
        $this->assertSame(['LOW:BAR'], $this->codes('&availability=low'));
        $this->assertSame(['LOW:BAR', 'OPEN:BAR'], $this->codes('&availability=available'));
    }

    public function test_restriction_filter(): void
    {
        $a = $this->makeRoomType(['code' => 'A'], 2);
        $b = $this->makeRoomType(['code' => 'B'], 2);
        $aBar = $this->product($a, $this->bar());
        $aBb = $this->product($a, $this->ratePlan('BB'));
        $bBar = $this->product($b, $this->bar());
        $this->ari($aBar, $this->day(1), ['price' => '100.00', 'cta' => 1]);
        $this->ari($aBb, $this->day(2), ['price' => '100.00', 'min_los' => 3]);
        $this->ari($bBar, $this->day(3), ['price' => '100.00', 'cutoff_days' => 2, 'max_los' => 7]);
        $this->inventory($b, $this->day(4), ['total_units' => 2, 'stop_sell' => 1]);

        $this->assertSame(['A:BAR'], $this->codes('&restriction=cta'));
        $this->assertSame([], $this->codes('&restriction=ctd'));
        $this->assertSame(['A:BB'], $this->codes('&restriction=min_los'));
        $this->assertSame(['B:BAR'], $this->codes('&restriction=cutoff'));
        $this->assertSame(['B:BAR'], $this->codes('&restriction=max_los'));
        // Room-type stop sell keeps the room type with all its rate plans.
        $this->assertSame(['B:BAR'], $this->codes('&restriction=stop_sell'));
        $this->assertSame(['A:BAR,BB', 'B:BAR'], $this->codes('&restriction=any'));
    }

    public function test_price_range_filter(): void
    {
        $rt = $this->makeRoomType(['code' => 'STD'], 2);
        $bar = $this->product($rt, $this->bar(), '1000.00');
        $bb = $this->product($rt, $this->ratePlan('BB'), '1000.00');
        $this->ari($bar, $this->day(1), ['price' => '500.00']);
        $this->ari($bb, $this->day(1), ['price' => '1500.00']);
        DB::table('ari_daily')->whereIn('product_id', [$bar, $bb])->where('stay_date', '!=', $this->day(1))->update(['price' => '1000.00']);

        $this->assertSame(['STD:BAR'], $this->codes('&price_max=600'));
        $this->assertSame(['STD:BB'], $this->codes('&price_min=1200'));
        $this->assertSame(['STD:BAR,BB'], $this->codes('&price_min=900&price_max=1100'));
        $this->assertSame([], $this->codes('&price_min=5000'));
        // Swapped bounds are put in order.
        $this->assertSame(['STD:BAR'], $this->codes('&price_min=600&price_max=400'));

        $this->actingAs($this->owner)->getJson($this->api('/calendar/grid?price_min=abc'))->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['price_min']]]);
        $this->actingAs($this->owner)->getJson($this->api('/calendar/grid?availability=bogus'))->assertStatus(422);
        $this->actingAs($this->owner)->getJson($this->api('/calendar/grid?restriction=bogus'))->assertStatus(422);
    }

    public function test_active_and_inactive_records(): void
    {
        $on = $this->makeRoomType(['code' => 'ON'], 2);
        $off = $this->makeRoomType(['code' => 'OFF'], 2);
        $this->product($on, $this->bar());
        $bb = $this->product($on, $this->ratePlan('BB'));
        $this->product($off, $this->bar());
        DB::table('room_types')->where('id', $off->id)->update(['is_active' => 0]);
        DB::table('room_type_rate_plans')->where('id', $bb)->update(['is_active' => 0]);

        $this->assertSame(['ON:BAR'], $this->codes(''));
        $this->assertSame(['ON:BAR,BB', 'OFF:BAR'], $this->codes('&status=all'));
        $this->assertSame(['ON:BB', 'OFF:BAR'], $this->codes('&status=inactive'));
    }

    public function test_older_status_links_still_work(): void
    {
        $f = CalendarQuery::normalizeFilters(['status' => 'sold_out']);
        $this->assertSame(['sold_out', null], [$f['availability'], $f['status']]);
        $this->assertSame('any', CalendarQuery::normalizeFilters(['status' => 'restricted'])['restriction']);
        $this->assertSame('stop_sell', CalendarQuery::normalizeFilters(['status' => 'stop_sell'])['restriction']);
        $this->assertNull(CalendarQuery::normalizeFilters(['room_type' => "x' or 1=1", 'price_min' => '-5'])['room_type']);
        $this->assertNull(CalendarQuery::normalizeFilters(['price_min' => '-5'])['price_min']);
    }

    public function test_page_keeps_filters_from_the_query_string(): void
    {
        $this->makeRoomType(['code' => 'STD'], 2);

        $html = $this->actingAs($this->owner)->get($this->page('/calendar?availability=low&restriction=cta&price_min=100&price_max=900&status=bogus'))->assertOk()->getContent();
        foreach (['"availability":"low"', '"restriction":"cta"', '"price_min":"100"', '"price_max":"900"', '"status":null'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
    }
}
