<?php

namespace Tests\Feature\Inventory;

use App\Domain\Accommodation\PhysicalUnitService;
use App\Domain\Accommodation\UnitBlockService;
use App\Domain\Inventory\InventoryService;
use App\Models\PhysicalUnit;
use App\Models\UnitBlock;
use App\Support\PropertyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Daily rows follow room types, PMS rooms, blocks and products (Phase 2 events). */
class HorizonAndListenersTest extends InventoryTestCase
{
    public function test_new_room_type_gets_inventory_for_the_horizon(): void
    {
        $before = $this->version();
        $roomType = $this->makeRoomType([], 3);

        $rows = DB::table('inventory_daily')->where('room_type_id', $roomType->id);
        $this->assertSame(60, (clone $rows)->count());
        $this->assertSame($this->today(), (clone $rows)->min('stay_date'));
        $this->assertSame($this->day(59)->toDateString(), (clone $rows)->max('stay_date'));
        $this->assertSame(3, (int) (clone $rows)->max('total_units'));
        $this->assertSame(3, (int) (clone $rows)->min('total_units'));
        $this->assertSame((int) $this->property->id, (int) (clone $rows)->value('property_id'));
        $this->assertGreaterThan($before, $this->version());
    }

    public function test_new_product_gets_default_price_and_rate_plan_restrictions(): void
    {
        $roomType = $this->makeRoomType();
        $plan = $this->plan('LONG', ['default_min_los' => 3, 'default_max_los' => 14, 'min_advance_days' => 2, 'max_advance_days' => 300]);
        $product = $this->product($roomType, $plan, ['default_price' => '2500.00']);

        $row = $this->ari($product, $this->day(10));
        $this->assertSame('2500.00', $row->price);
        $this->assertSame(3, (int) $row->min_los);
        $this->assertSame(14, (int) $row->max_los);
        $this->assertSame(2, (int) $row->cutoff_days);
        $this->assertSame(300, (int) $row->max_advance_days);
        $this->assertSame(60, DB::table('ari_daily')->where('product_id', $product->id)->count());

        $derived = $this->product($roomType, $this->plan('NRF'), ['pricing_mode' => 'derived', 'parent' => $product, 'adjust_type' => 'percent', 'adjust_value' => '-10']);
        $this->assertNull($this->ari($derived, $this->day(10))->price);
        $this->assertTrue(DB::table('ari_change_log')->where('product_id', $derived->id)->where('scope', 'rate')->exists());
    }

    public function test_horizon_command_fills_missing_days_only(): void
    {
        $roomType = $this->makeRoomType();
        $product = $this->product($roomType, $this->bar());
        DB::table('ari_daily')->where('product_id', $product->id)->where('stay_date', $this->day(5)->toDateString())->update(['price' => '777.00']);

        $this->artisan('inventory:horizon', ['--days' => 90])->assertSuccessful();

        $this->assertSame(90, DB::table('inventory_daily')->where('room_type_id', $roomType->id)->count());
        $this->assertSame(90, DB::table('ari_daily')->where('product_id', $product->id)->count());
        $this->assertSame('777.00', $this->ari($product, $this->day(5))->price, 'existing rows are never overwritten');
        $this->assertSame(0, app(InventoryService::class)->ensureHorizon($this->property->fresh(), 90), 'second run creates nothing');
    }

    public function test_adding_and_deactivating_rooms_changes_total_units(): void
    {
        $roomType = $this->makeRoomType([], 2);
        $this->inProperty($this->property);
        app(PhysicalUnitService::class)->addUnits($roomType, [['name' => 'X-1'], ['name' => 'X-2']]);
        $this->assertSame(4, $this->inv($roomType, $this->day(3))->total_units);

        $unit = PhysicalUnit::query()->where('name', 'X-1')->firstOrFail();
        app(PhysicalUnitService::class)->update($unit, ['is_active' => false]);
        $this->assertSame(3, $this->inv($roomType, $this->day(3))->total_units);
    }

    public function test_room_cannot_be_deactivated_when_all_rooms_are_sold(): void
    {
        $roomType = $this->makeRoomType([], 2);
        app(InventoryService::class)->reserve($roomType->id, $this->day(4), $this->day(5), 2);

        $this->inProperty($this->property);
        $unit = $this->firstUnit($roomType);
        try {
            app(PhysicalUnitService::class)->update($unit, ['is_active' => false]);
            $this->fail('Deactivation should be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('units', $e->errors());
        }
        $this->assertTrue((bool) $unit->fresh()->is_active);
        $this->assertSame(2, $this->inv($roomType, $this->day(4))->total_units);
    }

    public function test_blocks_take_rooms_out_of_sale_for_their_dates(): void
    {
        $roomType = $this->makeRoomType([], 3);
        $this->inProperty($this->property);
        $unit = $this->firstUnit($roomType);
        $block = app(UnitBlockService::class)->block($unit, 'out_of_order', $this->day(2)->toDateString(), $this->day(5)->toDateString());

        $this->assertSame(0, $this->inv($roomType, $this->day(1))->ooo_units);
        $this->assertSame(1, $this->inv($roomType, $this->day(2))->ooo_units);
        $this->assertSame(1, $this->inv($roomType, $this->day(4))->ooo_units);
        $this->assertSame(0, $this->inv($roomType, $this->day(5))->ooo_units);

        $second = PhysicalUnit::query()->where('room_type_id', $roomType->id)->orderByDesc('id')->firstOrFail();
        app(UnitBlockService::class)->block($second, 'maintenance', $this->day(4)->toDateString(), $this->day(6)->toDateString());
        $this->assertSame(2, $this->inv($roomType, $this->day(4))->ooo_units);

        app(UnitBlockService::class)->release(UnitBlock::query()->findOrFail($block->id));
        $this->assertSame(0, $this->inv($roomType, $this->day(2))->ooo_units);
        $this->assertSame(1, $this->inv($roomType, $this->day(4))->ooo_units);
        $this->assertTrue(DB::table('ari_change_log')->where('room_type_id', $roomType->id)->where('scope', 'inventory')->exists());
    }

    public function test_block_is_refused_when_the_rooms_are_sold(): void
    {
        $roomType = $this->makeRoomType([], 2);
        app(InventoryService::class)->reserve($roomType->id, $this->day(3), $this->day(4), 2);

        $this->inProperty($this->property);
        $unit = $this->firstUnit($roomType);
        try {
            app(UnitBlockService::class)->block($unit, 'out_of_order', $this->day(2)->toDateString(), $this->day(5)->toDateString());
            $this->fail('The block should be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('start_date', $e->errors());
        }
        $this->assertSame(0, UnitBlock::query()->count());
        $this->assertSame(0, $this->inv($roomType, $this->day(2))->ooo_units);
    }

    public function test_deactivated_blocked_room_no_longer_counts_as_out_of_order(): void
    {
        $roomType = $this->makeRoomType([], 3);
        $this->inProperty($this->property);
        $unit = $this->firstUnit($roomType);
        app(UnitBlockService::class)->block($unit, 'out_of_order', $this->day(1)->toDateString(), $this->day(3)->toDateString());
        app(PhysicalUnitService::class)->update($unit->fresh(), ['is_active' => false]);

        $row = $this->inv($roomType, $this->day(1));
        $this->assertSame(2, $row->total_units);
        $this->assertSame(0, $row->ooo_units);
    }

    public function test_tenant_rows_stay_in_their_property(): void
    {
        $mine = $this->makeRoomType();
        $theirs = $this->makeRoomType([], 2, $this->other);
        app(PropertyContext::class)->clear();

        $this->assertSame(0, DB::table('inventory_daily')->where('room_type_id', $theirs->id)->where('property_id', '!=', $this->other->id)->count());
        $this->assertSame(0, DB::table('inventory_daily')->where('room_type_id', $mine->id)->where('property_id', '!=', $this->property->id)->count());
    }
}
