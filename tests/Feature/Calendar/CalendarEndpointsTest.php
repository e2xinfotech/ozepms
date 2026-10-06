<?php

namespace Tests\Feature\Calendar;

use App\Domain\Inventory\AriApplyResult;
use App\Domain\Inventory\AriChangeSet;
use App\Domain\Inventory\AriService;
use App\Models\User;

/**
 * HTTP layer of the calendar: page access, grid fetch, and how cell / range / bulk edits are
 * turned into change sets. AriService is replaced by a recorder here; the engine's own tests
 * cover what a change set does to the daily tables (see CalendarSaveTest for the full path).
 */
class CalendarEndpointsTest extends CalendarTestCase
{
    /** @var list<AriChangeSet> */
    private array $applied = [];

    protected function setUp(): void
    {
        parent::setUp();
        $applied = &$this->applied;
        $this->app->instance(AriService::class, new class($applied) extends AriService
        {
            public function __construct(private array &$log) {}

            public function apply(AriChangeSet $changes, ?User $by = null): AriApplyResult
            {
                $this->log[] = $changes;
                $past = array_filter($changes->dates(), fn ($d) => $d < now()->toDateString());

                return new AriApplyResult(count($changes->roomTypeIds) * count($changes->dates()), count($changes->productIds) * count($changes->dates()), 0,
                    $past ? [['type' => 'product', 'id' => 1, 'field' => null, 'reason' => 'past', 'dates' => count($past)]] : [], 7);
            }
        });
    }

    public function test_page_renders_for_users_with_calendar_view(): void
    {
        $rt = $this->makeRoomType(['code' => 'STD', 'name' => 'Standard Room'], 2);
        $this->product($rt, $this->bar());

        $this->actingAs($this->owner)->get($this->page('/calendar'))->assertOk()
            ->assertSee($this->pageName('property/calendar/index'), false)
            ->assertSee('Standard Room', false);
        $this->actingAs($this->member('front_desk'))->get($this->page('/calendar?view=reservations&range=week'))->assertOk();
        $this->actingAs($this->member('revenue_manager'))->get($this->page('/calendar?range=bogus&status=bogus&from=xx'))->assertOk();
    }

    public function test_page_is_forbidden_without_calendar_view(): void
    {
        $this->actingAs($this->member('housekeeping'))->get($this->page('/calendar'))->assertForbidden();
        $this->actingAs($this->member('guest_relations'))->getJson($this->api('/calendar/grid'))->assertForbidden();
    }

    public function test_page_and_grid_of_another_property_are_not_found(): void
    {
        $this->actingAs($this->owner)->get('/p/'.$this->other->code.'/calendar')->assertNotFound();
        $this->actingAs($this->owner)->getJson('/web-api/p/'.$this->other->code.'/calendar/grid')->assertNotFound();
    }

    public function test_sidebar_links_to_the_calendar(): void
    {
        $html = $this->actingAs($this->owner)->get($this->page('/dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('"key":"calendar"', $html);
        $this->assertStringContainsString('\/p\/'.$this->property->code.'\/calendar', $html);
    }

    public function test_grid_returns_one_window(): void
    {
        $rt = $this->makeRoomType(['code' => 'STD'], 2);
        $this->product($rt, $this->bar());

        $this->actingAs($this->owner)->getJson($this->api('/calendar/grid?range=week&from='.$this->today()))->assertOk()
            ->assertJsonPath('grid.from', $this->today())
            ->assertJsonCount(14, 'grid.days')
            ->assertJsonPath('grid.room_types.0.code', 'STD')
            ->assertJsonStructure(['grid' => [
                'from', 'to', 'today', 'currency',
                'days' => [['date', 'day', 'dow', 'weekend']],
                'room_types' => [['id', 'code', 'name', 'image', 'units_count', 'units_range', 'inventory' => [['t', 's', 'h', 'o', 'l', 'a', 'ss', 'd']],
                    'products' => [['id', 'rate_plan' => ['id', 'code', 'name'], 'pricing_mode', 'parent', 'days' => [['p', 'o', 'd', 'min', 'max', 'cta', 'ctd', 'ss', 'cut', 'adv']]]],
                    'units' => [['id', 'name', 'status', 'bars']]]],
            ]]);

        $this->actingAs($this->owner)->getJson($this->api('/calendar/grid?range=month&from=2026-02'))->assertOk()
            ->assertJsonPath('grid.from', '2026-02-01')->assertJsonCount(28, 'grid.days');
    }

    public function test_grid_validates_filters(): void
    {
        $this->actingAs($this->owner)->getJson($this->api('/calendar/grid?range=year'))->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['range']]]);
        $this->actingAs($this->owner)->getJson($this->api('/calendar/grid?status=nope'))->assertStatus(422);
        $this->actingAs($this->owner)->getJson($this->api('/calendar/grid?from=12/03/2026'))->assertStatus(422);
    }

    public function test_grid_ignores_ids_of_another_property(): void
    {
        $this->makeRoomType(['code' => 'MINE'], 1);
        $theirs = $this->makeRoomType(['code' => 'THEIRS'], 1, $this->other);

        $this->actingAs($this->owner)->getJson($this->api('/calendar/grid?room_type='.$theirs->public_id))->assertOk()->assertJsonPath('grid.room_types', []);
    }

    public function test_cell_update_of_a_rate_row(): void
    {
        $rt = $this->makeRoomType(['code' => 'STD'], 2);
        $productId = $this->product($rt, $this->bar());
        $public = \App\Models\Product::acrossProperties()->findOrFail($productId)->public_id;

        $this->actingAs($this->owner)->putJson($this->api('/calendar/cell'), [
            'date' => $this->day(3), 'product_id' => $public, 'price' => '4500.50', 'min_los' => 2, 'cta' => true, 'occupancy_prices' => ['1' => '4000', '3' => null],
        ])->assertOk()->assertJsonPath('result.ari_rows', 1)->assertJsonPath('message', __('calendar.messages.saved'));

        $this->assertCount(1, $this->applied);
        $set = $this->applied[0];
        $this->assertSame([$productId], $set->productIds);
        $this->assertSame([], $set->roomTypeIds);
        $this->assertSame([$this->day(3)], $set->dates());
        $this->assertSame('4500.50', $set->price);
        $this->assertSame(2, $set->minLos);
        $this->assertTrue($set->cta);
        $this->assertSame([1 => '4000.00', 3 => null], $set->occupancyPrices);
        $this->assertSame($this->property->id, $set->propertyId);
    }

    public function test_cell_update_of_the_availability_row(): void
    {
        $rt = $this->makeRoomType(['code' => 'STD'], 2);

        $this->actingAs($this->owner)->putJson($this->api('/calendar/cell'), ['date' => $this->day(1), 'room_type_id' => $rt->public_id, 'stop_sell' => true, 'sell_limit' => 1])
            ->assertOk()->assertJsonPath('result.inventory_rows', 1);
        $this->assertSame([$rt->id], $this->applied[0]->roomTypeIds);
        $this->assertTrue($this->applied[0]->stopSell);
        $this->assertSame(1, $this->applied[0]->sellLimit);

        $this->actingAs($this->owner)->putJson($this->api('/calendar/cell'), ['date' => $this->day(1), 'room_type_id' => $rt->public_id, 'sell_limit' => 'none'])->assertOk();
        $this->assertFalse($this->applied[1]->sellLimit);
    }

    public function test_stop_sell_on_a_rate_row_closes_only_that_rate_plan(): void
    {
        $rt = $this->makeRoomType(['code' => 'STD'], 2);
        $public = \App\Models\Product::acrossProperties()->findOrFail($this->product($rt, $this->bar()))->public_id;

        $this->actingAs($this->owner)->putJson($this->api('/calendar/cell'), ['date' => $this->day(1), 'product_id' => $public, 'stop_sell' => true])->assertOk();

        $this->assertTrue($this->applied[0]->closed);
        $this->assertSame([], $this->applied[0]->roomTypeIds);
    }

    public function test_cell_update_validation(): void
    {
        $rt = $this->makeRoomType(['code' => 'STD'], 2);
        $public = \App\Models\Product::acrossProperties()->findOrFail($this->product($rt, $this->bar()))->public_id;

        $this->actingAs($this->owner)->putJson($this->api('/calendar/cell'), ['product_id' => $public, 'price' => '10'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['date']]]);
        $this->actingAs($this->owner)->putJson($this->api('/calendar/cell'), ['date' => $this->day(1), 'price' => '10'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['room_type_id', 'product_id']]]);
        $this->actingAs($this->owner)->putJson($this->api('/calendar/cell'), ['date' => $this->day(1), 'product_id' => $public, 'price' => '-5', 'min_los' => 'x', 'cta' => 'maybe', 'occupancy_prices' => ['2' => 'abc']])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['price', 'min_los', 'cta', 'occupancy_prices.2']]]);
        $this->actingAs($this->owner)->putJson($this->api('/calendar/cell'), ['date' => $this->day(1), 'product_id' => $public, 'min_los' => 5, 'max_los' => 2])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['max_los']]]);
        $this->actingAs($this->owner)->putJson($this->api('/calendar/cell'), ['date' => $this->day(1), 'product_id' => $public])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['fields']]]);
        $this->actingAs($this->owner)->putJson($this->api('/calendar/cell'), ['date' => $this->day(1), 'room_type_id' => $rt->public_id, 'sell_limit' => 'lots'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['sell_limit']]]);
        $this->assertSame([], $this->applied);
    }

    public function test_records_of_another_property_are_rejected(): void
    {
        $theirs = $this->makeRoomType(['code' => 'THEIRS'], 1, $this->other);
        $theirProduct = \App\Models\Product::acrossProperties()->findOrFail($this->product($theirs, $this->bar($this->other)))->public_id;

        $this->actingAs($this->owner)->putJson($this->api('/calendar/cell'), ['date' => $this->day(1), 'product_id' => $theirProduct, 'price' => '1'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['product_ids.0']]]);
        $this->actingAs($this->owner)->postJson($this->api('/calendar/bulk'), ['date_from' => $this->day(1), 'date_to' => $this->day(2), 'room_type_ids' => [$theirs->public_id], 'stop_sell' => true])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['room_type_ids.0']]]);
        $this->actingAs($this->owner)->postJson($this->api('/calendar/bulk'), ['date_from' => $this->day(1), 'date_to' => $this->day(2), 'rate_plan_ids' => [$this->bar($this->other)->public_id], 'price' => '1'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['rate_plan_ids.0']]]);
        // Another property's code in the URL
        $this->actingAs($this->owner)->putJson('/web-api/p/'.$this->other->code.'/calendar/cell', ['date' => $this->day(1), 'product_id' => $theirProduct, 'price' => '1'])->assertNotFound();
        $this->assertSame([], $this->applied);
    }

    public function test_updates_need_calendar_update_permission(): void
    {
        $rt = $this->makeRoomType(['code' => 'STD'], 2);
        $frontDesk = $this->member('front_desk');   // calendar.view only

        $this->actingAs($frontDesk)->getJson($this->api('/calendar/grid'))->assertOk();
        $this->actingAs($frontDesk)->putJson($this->api('/calendar/cell'), ['date' => $this->day(1), 'room_type_id' => $rt->public_id, 'stop_sell' => true])->assertForbidden();
        $this->actingAs($frontDesk)->putJson($this->api('/calendar/range'), ['date_from' => $this->day(1), 'date_to' => $this->day(3), 'room_type_ids' => [$rt->public_id], 'stop_sell' => true])->assertForbidden();
        $this->actingAs($frontDesk)->postJson($this->api('/calendar/bulk'), ['date_from' => $this->day(1), 'date_to' => $this->day(3), 'room_type_ids' => [$rt->public_id], 'stop_sell' => true])->assertForbidden();
        $this->actingAs($this->member('revenue_manager'))->putJson($this->api('/calendar/range'), ['date_from' => $this->day(1), 'date_to' => $this->day(3), 'room_type_ids' => [$rt->public_id], 'stop_sell' => true])->assertOk();
        $this->assertCount(1, $this->applied);
    }

    public function test_range_update_covers_consecutive_nights(): void
    {
        $rt = $this->makeRoomType(['code' => 'STD'], 2);
        $productId = $this->product($rt, $this->bar());
        $public = \App\Models\Product::acrossProperties()->findOrFail($productId)->public_id;

        $this->actingAs($this->owner)->putJson($this->api('/calendar/range'), ['date_from' => $this->day(2), 'date_to' => $this->day(5), 'product_ids' => [$public], 'price' => '99'])
            ->assertOk()->assertJsonPath('result.ari_rows', 4);
        $this->assertSame([$this->day(2), $this->day(3), $this->day(4), $this->day(5)], $this->applied[0]->dates());

        $this->actingAs($this->owner)->putJson($this->api('/calendar/range'), ['date_from' => $this->day(5), 'date_to' => $this->day(2), 'product_ids' => [$public], 'price' => '99'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['date_to']]]);
    }

    public function test_bulk_update_expands_rate_plans_and_weekdays(): void
    {
        $std = $this->makeRoomType(['code' => 'STD'], 2);
        $dlx = $this->makeRoomType(['code' => 'DLX'], 2);
        $bb = $this->ratePlan('BB');
        $stdBar = $this->product($std, $this->bar());
        $stdBb = $this->product($std, $bb);
        $dlxBar = $this->product($dlx, $this->bar());

        $this->actingAs($this->owner)->postJson($this->api('/calendar/bulk'), [
            'date_from' => $this->day(1), 'date_to' => $this->day(14), 'weekdays' => [6, 7],
            'rate_plan_ids' => [$this->bar()->public_id], 'min_los' => 2, 'max_los' => 7,
        ])->assertOk();

        $set = $this->applied[0];
        $this->assertEqualsCanonicalizing([$stdBar, $dlxBar], $set->productIds);
        $this->assertSame([6, 7], $set->weekdays);
        foreach ($set->dates() as $d) {
            $this->assertContains((int) date('N', strtotime($d)), [6, 7]);
        }

        // Room types narrow the rate plans to their products
        $this->actingAs($this->owner)->postJson($this->api('/calendar/bulk'), [
            'date_from' => $this->day(1), 'date_to' => $this->day(3), 'room_type_ids' => [$std->public_id], 'rate_plan_ids' => [$bb->public_id, $this->bar()->public_id], 'price' => '100',
        ])->assertOk();
        $this->assertEqualsCanonicalizing([$stdBar, $stdBb], $this->applied[1]->productIds);
        $this->assertSame([], $this->applied[1]->roomTypeIds);

        $this->actingAs($this->owner)->postJson($this->api('/calendar/bulk'), [
            'date_from' => $this->day(1), 'date_to' => $this->day(3), 'room_type_ids' => [$dlx->public_id], 'rate_plan_ids' => [$bb->public_id], 'price' => '100',
        ])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['rate_plan_ids']]]);

        $this->actingAs($this->owner)->postJson($this->api('/calendar/bulk'), ['date_from' => $this->day(1), 'date_to' => $this->day(3), 'weekdays' => [0, 8], 'room_type_ids' => [$std->public_id], 'stop_sell' => true])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['weekdays.0', 'weekdays.1']]]);
    }

    public function test_bulk_stop_sell_with_prices_applies_room_type_and_product_values_separately(): void
    {
        $std = $this->makeRoomType(['code' => 'STD'], 2);
        $bar = $this->product($std, $this->bar());

        $this->actingAs($this->owner)->postJson($this->api('/calendar/bulk'), [
            'date_from' => $this->day(1), 'date_to' => $this->day(2), 'room_type_ids' => [$std->public_id], 'rate_plan_ids' => [$this->bar()->public_id],
            'stop_sell' => true, 'price' => '500',
        ])->assertOk()->assertJsonPath('result.inventory_rows', 2)->assertJsonPath('result.ari_rows', 2);

        $this->assertCount(2, $this->applied);
        $this->assertSame([$std->id], $this->applied[0]->roomTypeIds);
        $this->assertTrue($this->applied[0]->stopSell);
        $this->assertNull($this->applied[0]->price);
        $this->assertSame([$bar], $this->applied[1]->productIds);
        $this->assertNull($this->applied[1]->stopSell);
        $this->assertSame('500.00', $this->applied[1]->price);
    }

    public function test_skipped_dates_are_reported_with_a_reason(): void
    {
        $rt = $this->makeRoomType(['code' => 'STD'], 2);

        $this->actingAs($this->owner)->putJson($this->api('/calendar/range'), ['date_from' => $this->day(-3), 'date_to' => $this->day(1), 'room_type_ids' => [$rt->public_id], 'stop_sell' => false])
            ->assertOk()->assertJsonPath('result.skipped.0.reason', 'past')->assertJsonPath('result.skipped.0.dates', 3)
            ->assertJsonPath('result.skipped.0.label', __('inventory.skipped.past'));
    }
}
