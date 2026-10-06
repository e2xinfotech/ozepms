<?php

namespace Tests\Feature\Calendar;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/** "Copy Values": preview + copy of rates and restrictions between date ranges (full path to the daily tables). */
class CalendarCopyTest extends CalendarTestCase
{
    private function price(int $productId, string $date): ?string
    {
        return DB::table('ari_daily')->where('product_id', $productId)->where('stay_date', $date)->value('price');
    }

    private function body(array $overrides = []): array
    {
        return $overrides + [
            'source_from' => $this->day(1), 'source_to' => $this->day(3),
            'target_from' => $this->day(10), 'target_to' => $this->day(15),
            'rate_plan_ids' => [$this->bar()->public_id],
            'copy_rates' => true, 'copy_restrictions' => false,
        ];
    }

    /** Monday on or after today + $min days. */
    private function monday(int $min): string
    {
        $d = $this->day($min);

        return date('N', strtotime($d)) === '1' ? $d : date('Y-m-d', strtotime($d.' next monday'));
    }

    public function test_preview_counts_without_saving_then_copy_repeats_the_source(): void
    {
        $rt = $this->makeRoomType(['code' => 'STD'], 2);
        $bar = $this->product($rt, $this->bar(), '1000.00');
        foreach ([1 => '100.00', 2 => '200.00', 3 => '300.00'] as $i => $p) {
            $this->ari($bar, $this->day($i), ['price' => $p]);
        }
        $version = (int) DB::table('properties')->where('id', $this->property->id)->value('ari_version');

        $preview = $this->actingAs($this->owner)->postJson($this->api('/calendar/copy/preview'), $this->body())->assertOk()->json('result');
        $this->assertTrue($preview['preview']);
        $this->assertSame([1, 6, 6], [$preview['target_products'], $preview['target_dates'], $preview['ari_rows']]);
        $this->assertNotSame('100.00', $this->price($bar, $this->day(10)));
        $this->assertSame($version, (int) DB::table('properties')->where('id', $this->property->id)->value('ari_version'));
        $this->assertDatabaseMissing('audit_logs', ['action' => 'ari.copied']);

        $this->actingAs($this->owner)->postJson($this->api('/calendar/copy'), $this->body())->assertOk()
            ->assertJsonPath('result.preview', false)->assertJsonPath('result.ari_rows', 6);
        $this->assertSame(['100.00', '200.00', '300.00', '100.00', '200.00', '300.00'], array_map(fn ($i) => $this->price($bar, $this->day($i)), range(10, 15)));
        // One version step and one audit record for the whole copy.
        $this->assertSame($version + 1, (int) DB::table('properties')->where('id', $this->property->id)->value('ari_version'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'ari.copied')->count());
    }

    public function test_weekday_alignment(): void
    {
        $rt = $this->makeRoomType(['code' => 'STD'], 2);
        $bar = $this->product($rt, $this->bar(), '1000.00');
        $mon = $this->monday(1);
        for ($i = 0; $i < 7; $i++) {     // Mon 101 … Sun 107
            $this->ari($bar, date('Y-m-d', strtotime("$mon +$i days")), ['price' => (string) (101 + $i).'.00']);
        }
        $target = date('Y-m-d', strtotime("$mon +17 days"));   // a Thursday
        $this->actingAs($this->owner)->postJson($this->api('/calendar/copy'), $this->body([
            'source_from' => $mon, 'source_to' => date('Y-m-d', strtotime("$mon +6 days")),
            'target_from' => $target, 'target_to' => date('Y-m-d', strtotime("$target +13 days")), 'align_weekdays' => true,
        ]))->assertOk();
        for ($i = 0; $i < 14; $i++) {
            $d = date('Y-m-d', strtotime("$target +$i days"));
            $this->assertSame((string) (100 + (int) date('N', strtotime($d))).'.00', $this->price($bar, $d), $d);
        }

        // A weekend-only source changes only Saturdays and Sundays.
        $sat = date('Y-m-d', strtotime("$mon +5 days"));
        $later = date('Y-m-d', strtotime("$mon +35 days"));   // Monday
        $before = $this->price($bar, date('Y-m-d', strtotime("$later +2 days")));
        $res = $this->actingAs($this->owner)->postJson($this->api('/calendar/copy'), $this->body([
            'source_from' => $sat, 'source_to' => date('Y-m-d', strtotime("$sat +1 day")),
            'target_from' => $later, 'target_to' => date('Y-m-d', strtotime("$later +13 days")), 'align_weekdays' => true,
        ]))->assertOk()->json('result');
        $this->assertSame('106.00', $this->price($bar, date('Y-m-d', strtotime("$later +5 days"))));
        $this->assertSame('107.00', $this->price($bar, date('Y-m-d', strtotime("$later +13 days"))));
        $this->assertSame($before, $this->price($bar, date('Y-m-d', strtotime("$later +2 days"))));
        $this->assertSame(10, collect($res['skipped'])->firstWhere('reason', 'no_source_weekday')['dates']);
    }

    public function test_restrictions_from_another_rate_plan(): void
    {
        $rt = $this->makeRoomType(['code' => 'STD'], 2);
        $bar = $this->product($rt, $this->bar(), '1000.00');
        $bb = $this->product($rt, $this->ratePlan('BB'), '1200.00');
        $this->ari($bb, $this->day(1), ['price' => '1200.00', 'min_los' => 3, 'cta' => 1, 'cutoff_days' => 2, 'max_advance_days' => 90]);
        $this->ari($bar, $this->day(20), ['price' => '999.00', 'ctd' => 1, 'min_los' => 5]);

        $this->actingAs($this->owner)->postJson($this->api('/calendar/copy'), $this->body([
            'source_from' => $this->day(1), 'source_to' => $this->day(1), 'target_from' => $this->day(20), 'target_to' => $this->day(21),
            'source_rate_plan_id' => $this->ratePlan('BB')->public_id, 'copy_rates' => false, 'copy_restrictions' => true,
        ]))->assertOk();

        $row = DB::table('ari_daily')->where('product_id', $bar)->where('stay_date', $this->day(20))->first();
        $this->assertSame(['999.00', 3, 1, 0, 2, 90], [$row->price, (int) $row->min_los, (int) $row->cta, (int) $row->ctd, (int) $row->cutoff_days, (int) $row->max_advance_days]);
        $this->assertSame(3, (int) DB::table('ari_daily')->where('product_id', $bar)->where('stay_date', $this->day(21))->value('min_los'));
    }

    public function test_occupancy_prices_follow_the_source(): void
    {
        $rt = $this->makeRoomType(['code' => 'STD', 'base_adults' => 2, 'max_adults' => 3], 2);
        $bar = $this->product($rt, $this->bar(), '1000.00');
        $this->ari($bar, $this->day(1), ['price' => '100.00']);
        $this->occupancyPrice($bar, $this->day(1), 3, '150.00');
        $this->ari($bar, $this->day(5), ['price' => '500.00']);
        $this->occupancyPrice($bar, $this->day(5), 1, '80.00');

        $this->actingAs($this->owner)->postJson($this->api('/calendar/copy'), $this->body([
            'source_from' => $this->day(1), 'source_to' => $this->day(1), 'target_from' => $this->day(5), 'target_to' => $this->day(5),
        ]))->assertOk();

        $this->assertSame('100.00', $this->price($bar, $this->day(5)));
        $this->assertSame([3 => '150.00'], DB::table('ari_daily_occupancy')->where('product_id', $bar)->where('stay_date', $this->day(5))->pluck('price', 'adults')->all());
    }

    public function test_derived_targets_keep_their_price_and_past_dates_are_skipped(): void
    {
        $rt = $this->makeRoomType(['code' => 'STD'], 2);
        $bar = $this->product($rt, $this->bar(), '1000.00');
        $nrf = $this->product($rt, $this->ratePlan('NRF'), null, $bar, 'percent', '-10', false);
        $this->ari($nrf, $this->day(1), ['min_los' => 2]);
        $this->ari($bar, $this->day(1), ['price' => '100.00']);

        $res = $this->actingAs($this->owner)->postJson($this->api('/calendar/copy'), $this->body([
            'source_from' => $this->day(1), 'source_to' => $this->day(1), 'target_from' => $this->day(-2), 'target_to' => $this->day(3),
            'rate_plan_ids' => [$this->ratePlan('NRF')->public_id], 'copy_rates' => true, 'copy_restrictions' => true,
        ]))->assertOk()->json('result');

        $reasons = array_column($res['skipped'], 'reason');
        $this->assertContains('derived_price', $reasons);
        $this->assertContains('past', $reasons);
        $this->assertNull($this->price($nrf, $this->day(3)));
        $this->assertSame(2, (int) DB::table('ari_daily')->where('product_id', $nrf)->where('stay_date', $this->day(3))->value('min_los'));
    }

    public function test_validation(): void
    {
        $rt = $this->makeRoomType(['code' => 'STD'], 2);
        $this->product($rt, $this->bar());

        $this->actingAs($this->owner)->postJson($this->api('/calendar/copy/preview'), $this->body(['copy_rates' => false]))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['copy']]]);
        $this->actingAs($this->owner)->postJson($this->api('/calendar/copy'), $this->body(['target_to' => $this->day(9)]))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['target_to']]]);
        $this->actingAs($this->owner)->postJson($this->api('/calendar/copy'), $this->body(['target_to' => $this->day(400)]))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['target_to']]]);
        $this->actingAs($this->owner)->postJson($this->api('/calendar/copy'), $this->body(['rate_plan_ids' => []]))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['rate_plan_ids']]]);
        $this->actingAs($this->owner)->postJson($this->api('/calendar/copy'), $this->body(['source_from' => 'soon']))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['source_from']]]);
        // A rate plan of another property is a field error, never a silent no-op.
        $this->actingAs($this->owner)->postJson($this->api('/calendar/copy'), $this->body(['rate_plan_ids' => [$this->bar($this->other)->public_id]]))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['rate_plan_ids.0']]]);
        $this->actingAs($this->owner)->postJson($this->api('/calendar/copy'), $this->body(['source_rate_plan_id' => $this->bar($this->other)->public_id]))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['source_rate_plan_id']]]);
    }

    public function test_permission_and_other_property(): void
    {
        $rt = $this->makeRoomType(['code' => 'STD'], 2);
        $bar = $this->product($rt, $this->bar(), '1000.00');
        $this->ari($bar, $this->day(1), ['price' => '100.00']);

        $this->actingAs($this->member('front_desk'))->postJson($this->api('/calendar/copy/preview'), $this->body())->assertForbidden();
        $this->actingAs($this->member('front_desk'))->postJson($this->api('/calendar/copy'), $this->body())->assertForbidden();
        $this->actingAs($this->member('revenue_manager'))->postJson($this->api('/calendar/copy/preview'), $this->body())->assertOk();
        $this->actingAs($this->owner)->postJson('/web-api/p/'.$this->other->code.'/calendar/copy', $this->body())->assertNotFound();
        $this->assertNotSame('100.00', $this->price($bar, $this->day(10)));
    }

    public function test_room_types_without_the_source_rate_plan_are_reported(): void
    {
        $a = $this->makeRoomType(['code' => 'A'], 2);
        $b = $this->makeRoomType(['code' => 'B'], 2);
        $aBar = $this->product($a, $this->bar(), '1000.00');
        $this->product($b, $this->bar(), '1000.00');
        $aBb = $this->product($a, $this->ratePlan('BB'), '1300.00');
        $this->ari($aBb, $this->day(1), ['price' => '1300.00']);

        $res = $this->actingAs($this->owner)->postJson($this->api('/calendar/copy'), $this->body([
            'source_from' => $this->day(1), 'source_to' => $this->day(1), 'target_from' => $this->day(8), 'target_to' => $this->day(8),
            'source_rate_plan_id' => $this->ratePlan('BB')->public_id,
        ]))->assertOk()->json('result');

        $this->assertSame(1, $res['target_products']);
        $this->assertSame('1300.00', $this->price($aBar, $this->day(8)));
        $this->assertSame(1, collect($res['skipped'])->firstWhere('reason', 'no_source_product')['dates']);
        $this->assertSame(Product::acrossProperties()->findOrFail($aBar)->property_id, $this->property->id);
    }
}
