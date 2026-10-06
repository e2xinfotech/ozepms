<?php

namespace Tests\Feature\Reports;

use App\Domain\Reports\ReportCatalog;
use App\Models\ReservationRoom;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Reservations\ReservationTestCase;

/** Report figures against known bookings, the pages, JSON, CSV, validation and access. */
class ReportsTest extends ReservationTestCase
{
    private function report(string $slug, array $query = [])
    {
        return $this->actingAs($this->owner)->getJson('/web-api/p/'.$this->property->code.'/reports/'.$slug.'?'.http_build_query($query));
    }

    private function kpi(array $data, string $key): mixed
    {
        return collect($data['summary'])->firstWhere('key', $key)['value'];
    }

    private function range(int $from, int $to): array
    {
        return ['from' => $this->day($from)->toDateString(), 'to' => $this->day($to)->toDateString()];
    }

    public function test_stay_figures_occupancy_adr_revpar_room_types_and_sources(): void
    {
        $this->book([$this->room($this->dlxBar, 1, 3, ['rate' => '1000'])]);
        $this->book([$this->room($this->steBar, 2, 3, ['rate' => '3000'])]);
        $available = (int) DB::table('inventory_daily')->where('property_id', $this->property->id)
            ->whereBetween('stay_date', [$this->day(1)->toDateString(), $this->day(2)->toDateString()])->selectRaw('SUM(total_units - ooo_units) AS a')->value('a');

        $d = $this->report('overview', $this->range(1, 2) + ['group' => 'day'])->assertOk()->json('data');
        $this->assertSame(3, $this->kpi($d, 'rooms_sold'));
        $this->assertSame('5000.00', $this->kpi($d, 'room_revenue'));
        $this->assertSame('1666.67', $this->kpi($d, 'adr'));
        $this->assertEqualsWithDelta(round(3 / $available * 100, 1), $this->kpi($d, 'occupancy'), 0.01);
        $this->assertSame(0, $this->kpi($d, 'bookings'), 'bookings count by booking date (made today)');
        $this->assertSame(2, $this->kpi($this->report('overview', $this->range(0, 2))->json('data'), 'bookings'));
        $this->assertCount(2, $d['tables'][0]['rows']);
        $this->assertSame(3, $d['tables'][0]['totals']['sold']);

        $rt = $this->report('room-types', $this->range(1, 2))->json('data.tables.0.rows');
        $this->assertSame(['3000.00', '2000.00'], collect($rt)->sortByDesc('revenue')->pluck('revenue')->values()->all());

        $src = $this->report('sources', $this->range(0, 2))->json('data.tables.0');
        $this->assertSame(3, $src['totals']['sold']);
        $this->assertSame(2, $src['totals']['bookings']);
        $this->assertSame(3, $this->report('rate-plans', $this->range(1, 2))->json('data.tables.0.totals.sold'));

        $week = $this->report('occupancy', ['from' => $this->day(1)->toDateString(), 'to' => $this->day(30)->toDateString(), 'group' => 'month'])->json('data.tables.0.rows');
        $this->assertLessThanOrEqual(2, count($week), 'grouped by month');
    }

    public function test_booking_lists_cancellations_no_shows_and_pickup(): void
    {
        $keep = $this->book([$this->room($this->dlxBar, 3, 5)]);
        $gone = $this->book([$this->room($this->dlxBar, 6, 8)]);
        $noShow = $this->book([$this->room($this->steBar, 0, 2)]);
        $this->inProperty($this->property);
        $this->service()->cancel($gone, 'Changed plans', $this->owner);
        $this->service()->noShow($noShow, $this->owner);

        $list = $this->report('reservations', $this->range(0, 0) + ['basis' => 'booked'])->assertOk()->json('data');
        $this->assertSame(3, $this->kpi($list, 'reservations'));
        $this->assertSame($keep->booking_ref, collect($list['tables'][0]['rows'])->firstWhere('id', $keep->public_id)['ref']);
        $this->assertSame(1, $this->kpi($this->report('reservations', $this->range(0, 0) + ['status' => 'cancelled'])->json('data'), 'reservations'));
        $this->assertSame(1, $this->kpi($this->report('reservations', $this->range(3, 3) + ['basis' => 'arrival'])->json('data'), 'reservations'));

        $c = $this->report('cancellations', $this->range(0, 0))->json('data');
        $this->assertSame(1, $this->kpi($c, 'cancellations'));
        $this->assertSame(2, $this->kpi($c, 'room_nights_lost'));
        $this->assertGreaterThan(0, (float) $this->kpi($c, 'revenue_lost'), 'value of the cancelled nights');
        $this->assertSame('Changed plans', $c['tables'][0]['rows'][0]['reason']);

        $n = $this->report('no-shows', $this->range(0, 1))->json('data');
        $this->assertSame(1, $this->kpi($n, 'no_shows'));
        $this->assertSame((string) $noShow->fresh()->cancellation_fee, $this->kpi($n, 'fees_charged'));

        $p = $this->report('pickup', $this->range(0, 10) + ['pickup_days' => 7])->json('data');
        $this->assertSame(2, $this->kpi($p, 'on_the_books'), 'only the kept booking is on the books');
        $this->assertSame(2, $this->kpi($p, 'pickup_rooms'), 'made today, so all of it is pickup');
    }

    public function test_revenue_taxes_and_daily_report_follow_the_ledger(): void
    {
        $r = $this->book([$this->room($this->dlxBar, 0, 2, ['rate' => '1000'])]);
        $this->inProperty($this->property);
        $room = ReservationRoom::acrossProperties()->where('reservation_id', $r->id)->first();
        $this->service()->assignUnit($r, $room, $this->units($this->deluxe)->first(), $this->owner);
        $this->service()->checkIn($r->fresh(), null, $this->owner);
        $posted = DB::table('folio_lines')->where('property_id', $this->property->id)->selectRaw('COALESCE(SUM(amount), 0) AS a, COALESCE(SUM(tax_amount), 0) AS t')->first();

        $rev = $this->report('revenue', $this->range(-1, 1))->assertOk()->json('data');
        $this->assertSame(number_format((float) $posted->a, 2, '.', ''), $this->kpi($rev, 'posted_revenue'));
        $tax = $this->report('taxes', $this->range(-1, 1))->json('data');
        $this->assertSame(number_format((float) $posted->t, 2, '.', ''), $this->kpi($tax, 'taxes'));

        $daily = $this->report('daily', ['date' => $this->day(0)->toDateString()])->json('data');
        $this->assertSame(1, $this->kpi($daily, 'in_house'));
        $this->assertSame(1, $this->kpi($daily, 'arrivals'));
        $manager = collect($daily['tables'][0]['rows'])->keyBy('metric');
        $this->assertSame(1, $manager[__('reports.kpi.rooms_sold')]['day']);
        $this->assertSame($r->booking_ref, $daily['tables'][3]['rows'][0]['ref']);
    }

    public function test_pages_json_csv_validation_and_access(): void
    {
        $this->book([$this->room($this->dlxBar, 1, 2)]);
        $base = '/p/'.$this->property->code.'/reports';
        $this->actingAs($this->owner)->get($base)->assertOk()->assertSee($this->pageName('property/reports/index'), false);
        foreach (array_keys(ReportCatalog::REPORTS) as $key) {
            $this->actingAs($this->owner)->get($base.'/'.ReportCatalog::slug($key))->assertOk();
            $this->report(ReportCatalog::slug($key))->assertOk()->assertJsonStructure(['filters', 'data' => ['summary', 'tables']]);
        }
        $this->actingAs($this->owner)->get($base.'/nope')->assertNotFound();

        $csv = $this->actingAs($this->owner)->get('/web-api/p/'.$this->property->code.'/reports/occupancy/export?'.http_build_query($this->range(0, 3)));
        $csv->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $body = $csv->streamedContent();
        $this->assertStringContainsString(__('reports.col.occupancy'), $body);
        $this->assertStringContainsString($this->day(1)->toDateString(), $body);

        $this->report('overview', ['from' => $this->day(5)->toDateString(), 'to' => $this->day(1)->toDateString()])->assertStatus(422);
        $this->report('overview', ['from' => $this->day(0)->toDateString(), 'to' => $this->day(500)->toDateString()])->assertStatus(422);
        $this->report('overview', ['group' => 'year'])->assertStatus(422);

        $this->actingAs($this->actingMember('front_desk'))->getJson('/web-api/p/'.$this->property->code.'/reports/overview')->assertForbidden();
        $this->actingAs($this->otherOwner)->getJson('/web-api/p/'.$this->property->code.'/reports/overview')->assertNotFound();

        // A plan without reports.
        $plan = SubscriptionPlan::query()->whereKey($this->property->currentSubscription->plan_id)->first();
        $plan->forceFill(['features' => array_merge((array) $plan->features, ['reports' => false])])->save();
        $this->report('overview')->assertForbidden();
    }

    public function test_room_type_filter_and_other_property_isolation(): void
    {
        $this->book([$this->room($this->dlxBar, 1, 2)]);
        $this->book([$this->room($this->steBar, 1, 2)]);
        $this->book([$this->room($this->product($this->makeRoomType(['code' => 'OTH'], 2, $this->other), $this->bar($this->other)), 1, 2)], [], $this->other);
        $only = $this->report('overview', $this->range(1, 1) + ['room_type' => $this->suite->public_id])->json('data');
        $this->assertSame(1, $this->kpi($only, 'rooms_sold'));
        $this->assertSame(2, $this->kpi($this->report('overview', $this->range(1, 1))->json('data'), 'rooms_sold'));
        $this->assertSame(0, $this->kpi($this->report('overview', $this->range(1, 1) + ['room_type' => 'NOTAREALID'])->json('data'), 'rooms_sold'));
    }
}
