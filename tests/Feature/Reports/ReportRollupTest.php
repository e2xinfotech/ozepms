<?php

namespace Tests\Feature\Reports;

use App\Domain\Reports\ReportRollupService;
use App\Models\ReservationRoom;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Reservations\ReservationTestCase;

/** Report rollups follow every booking change and always equal the live booking tables. */
class ReportRollupTest extends ReservationTestCase
{
    /** @return array{sold: int, revenue: string} totals of stats_daily over [in, out) */
    private function stats(int $in, int $out, ?int $roomTypeId = null): array
    {
        $row = DB::table('stats_daily')->where('property_id', $this->property->id)
            ->whereBetween('stay_date', [$this->day($in)->toDateString(), $this->day($out - 1)->toDateString()])
            ->when($roomTypeId, fn ($q) => $q->where('room_type_id', $roomTypeId))
            ->selectRaw('COALESCE(SUM(rooms_sold), 0) AS sold, COALESCE(SUM(room_revenue), 0) AS revenue')->first();

        return ['sold' => (int) $row->sold, 'revenue' => number_format((float) $row->revenue, 2, '.', '')];
    }

    private function live(int $in, int $out): array
    {
        $row = DB::table('reservation_room_nights')->where('property_id', $this->property->id)->where('is_active', 1)
            ->whereBetween('stay_date', [$this->day($in)->toDateString(), $this->day($out - 1)->toDateString()])
            ->selectRaw('COUNT(*) AS sold, COALESCE(SUM(net_price), 0) AS revenue')->first();

        return ['sold' => (int) $row->sold, 'revenue' => number_format((float) $row->revenue, 2, '.', '')];
    }

    private function assertMatchesLive(): void
    {
        $this->assertSame($this->live(-5, 20), $this->stats(-5, 20));
        $mix = DB::table('stats_daily_mix')->where('property_id', $this->property->id)->selectRaw('COALESCE(SUM(rooms_sold), 0) AS s, COALESCE(SUM(room_revenue), 0) AS r')->first();
        $this->assertSame($this->live(-5, 20), ['sold' => (int) $mix->s, 'revenue' => number_format((float) $mix->r, 2, '.', '')]);
    }

    public function test_create_modify_cancel_keep_the_rollup_equal_to_live_data(): void
    {
        $r = $this->book([$this->room($this->dlxBar, 2, 5)]);
        $this->assertSame(3, $this->stats(2, 5, $this->deluxe->id)['sold']);
        $this->assertSame(1, (int) DB::table('stats_daily')->where('property_id', $this->property->id)->where('stay_date', $this->day(2)->toDateString())->where('room_type_id', $this->deluxe->id)->value('arrivals'));
        $this->assertSame(1, (int) DB::table('stats_daily')->where('property_id', $this->property->id)->where('stay_date', $this->day(5)->toDateString())->where('room_type_id', $this->deluxe->id)->value('departures'));
        $this->assertMatchesLive();

        $roomId = ReservationRoom::acrossProperties()->where('reservation_id', $r->id)->value('id');
        $this->inProperty($this->property);
        $this->service()->modify($r, ['rooms' => [$this->room($this->dlxBar, 4, 8, ['id' => $roomId])]], $this->owner);
        $this->assertSame(0, $this->stats(2, 4)['sold'], 'old nights left the rollup');
        $this->assertSame(4, $this->stats(4, 8)['sold']);
        $this->assertMatchesLive();

        $this->service()->cancel($r->fresh(), 'Plans changed', $this->owner);
        $this->assertSame(0, $this->stats(4, 8)['sold']);
        $this->assertSame(1, (int) DB::table('stats_daily')->where('property_id', $this->property->id)->where('stay_date', $this->day(4)->toDateString())->sum('cancellations'));
        $this->assertMatchesLive();
    }

    public function test_no_show_and_units_and_mix_by_rate_plan_and_source(): void
    {
        $today = $this->book([$this->room($this->dlxBar, 0, 2)]);
        $this->book([$this->room($this->steBar, 1, 3)]);
        $this->inProperty($this->property);
        $this->service()->noShow($today, $this->owner);

        $row = DB::table('stats_daily')->where('property_id', $this->property->id)->where('stay_date', $this->day(0)->toDateString())->where('room_type_id', $this->deluxe->id)->first();
        $this->assertSame(1, (int) $row->no_shows);
        $this->assertSame(0, (int) $row->rooms_sold);
        $this->assertSame((int) DB::table('inventory_daily')->where('room_type_id', $this->deluxe->id)->where('stay_date', $this->day(0)->toDateString())->value('total_units'), (int) $row->units_total);

        $mix = DB::table('stats_daily_mix')->where('property_id', $this->property->id)->get();
        $this->assertSame([$this->steBar->rate_plan_id], $mix->pluck('rate_plan_id')->unique()->map(fn ($v) => (int) $v)->values()->all());
        $this->assertSame(2, (int) $mix->sum('rooms_sold'));
        $this->assertMatchesLive();
    }

    public function test_full_rebuild_is_idempotent_and_isolated_per_property(): void
    {
        $this->book([$this->room($this->dlxBar, 1, 4)]);
        app(ReportRollupService::class)->refreshAll($this->property->id);
        $before = DB::table('stats_daily')->where('property_id', $this->property->id)->count();
        $this->assertGreaterThan(8, $before, 'every inventory night gets a row, booked or not');
        app(ReportRollupService::class)->refreshAll($this->property->id);
        $this->assertSame($before, DB::table('stats_daily')->where('property_id', $this->property->id)->count());
        $this->assertSame(0, (int) DB::table('stats_daily')->where('property_id', $this->other->id)->sum('rooms_sold'));
        $this->assertMatchesLive();
        $this->artisan('reports:refresh', ['--property' => $this->property->code])->assertSuccessful();
        $this->artisan('reports:refresh', ['--property' => 'NOPE'])->assertFailed();
        $this->assertMatchesLive();
    }
}
