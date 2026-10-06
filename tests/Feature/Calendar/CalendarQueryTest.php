<?php

namespace Tests\Feature\Calendar;

use App\Domain\Inventory\Queries\CalendarQuery;
use App\Models\Property;
use App\Models\UnitBlock;
use App\Support\PropertyContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CalendarQueryTest extends CalendarTestCase
{
    private function window(int $days = 14, array $filters = [], ?Property $property = null, ?string $from = null): array
    {
        $property ??= $this->property;
        app(PropertyContext::class)->set($property, null);

        return app(CalendarQuery::class)->window($property->fresh(), CarbonImmutable::parse($from ?? $this->today($property)), $days, $filters);
    }

    public function test_empty_property_returns_the_days_only(): void
    {
        $grid = $this->window(31);

        $this->assertCount(31, $grid['days']);
        $this->assertSame([], $grid['room_types']);
        $this->assertSame($this->today(), $grid['from']);
        $this->assertSame($this->day(30), $grid['to']);
    }

    public function test_window_resolution_for_month_and_two_weeks(): void
    {
        [$from, $days] = CalendarQuery::resolveWindow('month', '2026-02-17', $this->property);
        $this->assertSame('2026-02-01', $from->toDateString());
        $this->assertSame(28, $days);
        [$from, $days] = CalendarQuery::resolveWindow('week', '2026-03-05', $this->property);
        $this->assertSame('2026-03-05', $from->toDateString());
        $this->assertSame(14, $days);
        [$from] = CalendarQuery::resolveWindow('month', 'garbage', $this->property);
        $this->assertSame(substr($this->today(), 0, 8).'01', $from->toDateString());
    }

    public function test_inventory_rows_give_availability_and_flags(): void
    {
        $rt = $this->makeRoomType(['code' => 'STD'], 2);
        $this->inventory($rt, $this->day(0), ['total_units' => 2, 'sold' => 2]);
        $this->inventory($rt, $this->day(1), ['total_units' => 2, 'sold' => 0, 'stop_sell' => 1]);
        $this->inventory($rt, $this->day(2), ['total_units' => 2, 'sold' => 0, 'held' => 1, 'sell_limit' => 2, 'ooo_units' => 0]);
        $this->inventory($rt, $this->day(3), ['total_units' => 2, 'sell_limit' => 1, 'ooo_units' => 0]);

        $inv = $this->window()['room_types'][0]['inventory'];

        $this->assertSame(0, $inv[0]['a']);
        $this->assertSame(2, $inv[0]['s']);
        $this->assertTrue($inv[1]['ss']);
        $this->assertSame(2, $inv[1]['a']);
        $this->assertSame(1, $inv[2]['a']);
        $this->assertSame(1, $inv[2]['h']);
        $this->assertSame(1, $inv[3]['a']);
        $this->assertSame(1, $inv[3]['l']);
        $this->assertFalse($inv[0]['d']);
    }

    public function test_missing_inventory_rows_fall_back_to_rooms_blocks_and_room_nights(): void
    {
        $rt = $this->makeRoomType(['code' => 'DLX'], 3);
        DB::table('inventory_daily')->where('room_type_id', $rt->id)->delete();
        $units = \App\Models\PhysicalUnit::acrossProperties()->where('room_type_id', $rt->id)->orderBy('id')->get();
        $this->stay($units[0], $this->day(1), 2);
        UnitBlock::acrossProperties()->insert([
            'property_id' => $this->property->id, 'unit_id' => $units[1]->id, 'block_type' => 'out_of_order',
            'start_date' => $this->day(2), 'end_date' => $this->day(4), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $inv = $this->window()['room_types'][0]['inventory'];

        $this->assertSame(['t' => 3, 's' => 0, 'h' => 0, 'o' => 0, 'l' => null, 'a' => 3, 'ss' => false, 'd' => true], $inv[0]);
        $this->assertSame(2, $inv[1]['a']);  // one sold
        $this->assertSame(1, $inv[2]['a']);  // one sold, one out of order
        $this->assertSame(2, $inv[3]['a']);  // out of order only
        $this->assertSame(3, $inv[4]['a']);
    }

    public function test_product_prices_restrictions_and_occupancy(): void
    {
        $rt = $this->makeRoomType(['code' => 'STD', 'base_adults' => 2, 'max_adults' => 3], 1);
        $bar = $this->product($rt, $this->bar(), '1000.00');
        $this->ari($bar, $this->day(0), ['price' => '1200.00', 'min_los' => 2, 'max_los' => 30, 'cta' => 1]);
        $this->ari($bar, $this->day(1), ['price' => null, 'ctd' => 1, 'stop_sell' => 1, 'cutoff_days' => 3]);
        $this->occupancyPrice($bar, $this->day(0), 1, '900.00');
        $this->occupancyPrice($bar, $this->day(0), 3, '1500.00');

        $product = collect($this->window()['room_types'][0]['products'])->firstWhere('rate_plan.code', 'BAR');
        $days = $product['days'];

        $this->assertSame('1200.00', $days[0]['p']);
        $this->assertFalse($days[0]['d']);
        $this->assertSame(2, $days[0]['min']);
        $this->assertSame(30, $days[0]['max']);
        $this->assertTrue($days[0]['cta']);
        $this->assertSame(['1' => '900.00', '3' => '1500.00'], $days[0]['o']);
        // Stored row without a price: the default price is shown and flagged
        $this->assertSame('1000.00', $days[1]['p']);
        $this->assertTrue($days[1]['d']);
        $this->assertTrue($days[1]['ctd']);
        $this->assertTrue($days[1]['ss']);
        $this->assertSame(3, $days[1]['cut']);
        // No row at all: defaults of the rate plan
        $this->assertSame('1000.00', $days[2]['p']);
        $this->assertSame(1, $days[2]['min']);
        $this->assertFalse($days[2]['cta']);
        $this->assertSame('manual', $product['pricing_mode']);
    }

    public function test_derived_products_follow_the_parent_price_and_restrictions(): void
    {
        $rt = $this->makeRoomType(['code' => 'STD'], 1);
        $bar = $this->product($rt, $this->bar(), '1000.00');
        $nrf = $this->product($rt, $this->ratePlan('NRF'), null, $bar, 'percent', '-10');
        $own = $this->product($rt, $this->ratePlan('OWN'), null, $bar, 'fixed', '250', inherit: false);
        $this->ari($bar, $this->day(0), ['price' => '2000.00', 'min_los' => 3, 'cta' => 1]);
        $this->ari($nrf, $this->day(0), ['stop_sell' => 1]);
        $this->ari($own, $this->day(0), ['min_los' => 1, 'ctd' => 1]);

        $products = collect($this->window()['room_types'][0]['products'])->keyBy('rate_plan.code');

        $n = $products['NRF'];
        $this->assertSame('derived', $n['pricing_mode']);
        $this->assertSame($this->bar()->name.' (BAR)', $n['parent']);
        $this->assertTrue($n['inherits_restrictions']);
        $this->assertSame('1800.00', $n['days'][0]['p']);
        $this->assertSame('900.00', $n['days'][1]['p']);   // parent default 1000 − 10 %
        $this->assertSame(3, $n['days'][0]['min']);
        $this->assertTrue($n['days'][0]['cta']);
        $this->assertTrue($n['days'][0]['ss']);              // own stop sell still applies
        $this->assertTrue($n['days'][0]['i']);

        $o = $products['OWN'];
        $this->assertSame('2250.00', $o['days'][0]['p']);
        $this->assertSame(1, $o['days'][0]['min']);
        $this->assertFalse($o['days'][0]['cta']);
        $this->assertTrue($o['days'][0]['ctd']);
    }

    public function test_reservation_bars_are_grouped_per_stay_and_clipped_to_the_window(): void
    {
        $rt = $this->makeRoomType(['code' => 'STD'], 3);
        $units = \App\Models\PhysicalUnit::acrossProperties()->where('room_type_id', $rt->id)->orderBy('id')->get();
        $ref = $this->stay($units[0], $this->day(2), 3, 'confirmed', 'John Smith');
        $this->stay($units[0], $this->day(5), 1, 'checked_in', 'Emily Johnson');
        $this->stay($units[1], $this->day(12), 4, 'pending');      // runs past the 14-day window
        $this->stay($units[2], $this->day(0), 2, 'cancelled');     // never shown

        $rooms = collect($this->window()['room_types'][0]['units'])->keyBy('name');

        $bars = $rooms[$units[0]->name]['bars'];
        $this->assertCount(2, $bars);
        $this->assertSame(['reservation', 2, 4, 'confirmed', 'John Smith', $ref], [$bars[0]['kind'], $bars[0]['start'], $bars[0]['end'], $bars[0]['status'], $bars[0]['guest'], $bars[0]['reference']]);
        $this->assertFalse($bars[0]['cont_before']);
        $this->assertFalse($bars[0]['cont_after']);
        $this->assertSame([5, 5, 'in_house'], [$bars[1]['start'], $bars[1]['end'], $bars[1]['status']]);

        $long = $rooms[$units[1]->name]['bars'][0];
        $this->assertSame([12, 13, 'pending', true], [$long['start'], $long['end'], $long['status'], $long['cont_after']]);
        $this->assertSame([], $rooms[$units[2]->name]['bars']);
    }

    public function test_window_starting_inside_a_stay_marks_the_bar_as_continued(): void
    {
        $rt = $this->makeRoomType(['code' => 'STD'], 1);
        $unit = $this->firstUnit($rt);
        $this->stay($unit, $this->day(0), 4);

        $bar = $this->window(14, [], null, $this->day(2))['room_types'][0]['units'][0]['bars'][0];

        $this->assertSame([0, 1, true, false], [$bar['start'], $bar['end'], $bar['cont_before'], $bar['cont_after']]);
    }

    public function test_unit_blocks_become_bars_and_set_the_room_status(): void
    {
        $rt = $this->makeRoomType(['code' => 'STD'], 2);
        $units = \App\Models\PhysicalUnit::acrossProperties()->where('room_type_id', $rt->id)->orderBy('id')->get();
        UnitBlock::acrossProperties()->insert([
            ['property_id' => $this->property->id, 'unit_id' => $units[0]->id, 'block_type' => 'maintenance', 'start_date' => $this->day(-2), 'end_date' => $this->day(3), 'reason' => 'Paint', 'created_at' => now(), 'updated_at' => now()],
            ['property_id' => $this->property->id, 'unit_id' => $units[1]->id, 'block_type' => 'owner_hold', 'start_date' => $this->day(6), 'end_date' => $this->day(8), 'reason' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->reserveNight($units[1], $this->today());

        $rooms = collect($this->window()['room_types'][0]['units'])->keyBy('name');

        $this->assertSame('out_of_service', $rooms[$units[0]->name]['status']);
        $this->assertSame('occupied', $rooms[$units[1]->name]['status']);
        $block = $rooms[$units[0]->name]['bars'][0];
        $this->assertSame(['block', 0, 2, 'out_of_service', 'Paint', true, false], [$block['kind'], $block['start'], $block['end'], $block['status'], $block['reason'], $block['cont_before'], $block['cont_after']]);
        $hold = collect($rooms[$units[1]->name]['bars'])->firstWhere('kind', 'block');
        $this->assertSame([6, 7, 'blocked'], [$hold['start'], $hold['end'], $hold['status']]);
    }

    public function test_filters_narrow_room_types_products_and_rooms(): void
    {
        $std = $this->makeRoomType(['code' => 'STD', 'name' => 'Standard'], 2);
        $dlx = $this->makeRoomType(['code' => 'DLX', 'name' => 'Deluxe'], 2);
        $this->product($std, $this->bar());
        $this->product($std, $this->ratePlan('BB'));
        $this->product($dlx, $this->bar());
        $this->inventory($dlx, $this->day(1), ['total_units' => 2, 'sold' => 2]);
        DB::table('room_types')->where('id', $std->id)->update(['is_active' => 0]);

        $this->assertSame(['DLX'], array_column($this->window()['room_types'], 'code'));
        $this->assertSame(['STD', 'DLX'], array_column($this->window(14, ['status' => 'all'])['room_types'], 'code'));
        $this->assertSame(['STD'], array_column($this->window(14, ['status' => 'inactive'])['room_types'], 'code'));
        $this->assertSame(['DLX'], array_column($this->window(14, ['status' => 'sold_out'])['room_types'], 'code'));
        $this->assertSame([], $this->window(14, ['status' => 'stop_sell'])['room_types']);

        $byPlan = $this->window(14, ['status' => 'all', 'rate_plan' => $this->ratePlan('BB')->public_id])['room_types'];
        $this->assertSame(['BB'], array_column(array_column($byPlan[0]['products'], 'rate_plan'), 'code'));
        $this->assertSame([], $byPlan[1]['products']);

        $unit = $this->firstUnit($dlx);
        $byUnit = $this->window(14, ['unit' => $unit->public_id])['room_types'];
        $this->assertSame(['DLX'], array_column($byUnit, 'code'));
        $this->assertSame([$unit->name], array_column($byUnit[0]['units'], 'name'));

        $this->assertSame(['STD'], array_column($this->window(14, ['status' => 'all', 'room_type' => $std->public_id])['room_types'], 'code'));
    }

    public function test_other_property_data_never_appears(): void
    {
        $mine = $this->makeRoomType(['code' => 'MINE'], 1);
        $theirs = $this->makeRoomType(['code' => 'THEIRS'], 2, $this->other);
        $p = $this->product($theirs, $this->bar($this->other));
        $this->ari($p, $this->day(0), ['price' => '5.00']);
        $this->stay($this->firstUnit($theirs), $this->day(0), 2);

        $grid = $this->window();
        $this->assertSame(['MINE'], array_column($grid['room_types'], 'code'));
        // A room type id of another property used as a filter matches nothing.
        $this->assertSame([], $this->window(14, ['room_type' => $theirs->public_id])['room_types']);
        $this->assertSame([], $this->window(14, ['unit' => $this->firstUnit($theirs)->public_id])['room_types']);
        $this->assertSame('MINE', $mine->code);
    }

    public function test_the_number_of_queries_does_not_grow_with_the_property(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->window(31);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $rt = $this->makeRoomType(['code' => 'A'], 2);
        $this->product($rt, $this->bar());
        $small = $count();

        foreach (['B', 'C', 'D', 'E'] as $code) {
            $rt = $this->makeRoomType(['code' => $code], 3);
            $bar = $this->product($rt, $this->bar());
            $this->product($rt, $this->ratePlan('BB'));
            $this->product($rt, $this->ratePlan('NRF'), null, $bar);
            $this->stay($this->firstUnit($rt), $this->day(1), 3);
            $this->ari($bar, $this->day(2), ['price' => '10.00']);
        }
        $large = $count();

        $this->assertLessThanOrEqual(12, $large);
        $this->assertSame($small, $large);
    }
}
