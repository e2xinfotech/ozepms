<?php

namespace Tests\Feature\Reservations;

use App\Domain\Platform\PlatformStatsService;
use Illuminate\Support\Facades\DB;

/** Super Admin dashboard: room revenue of the month, one total per property currency. */
class PlatformRevenueTest extends ReservationTestCase
{
    public function test_revenue_of_the_month_sums_active_room_nights(): void
    {
        $this->book([$this->room($this->dlxBar, 0, 1)]);
        $expected = DB::table('reservation_room_nights')->where('property_id', $this->property->id)->where('is_active', true)
            ->where('stay_date', '>=', now()->startOfMonth()->toDateString())->where('stay_date', '<=', now()->endOfMonth()->toDateString())->sum('net_price');

        $revenue = app(PlatformStatsService::class)->summary()['revenue_month'];
        $row = collect($revenue)->firstWhere('currency', $this->property->currency_code);

        $this->assertNotNull($row);
        $this->assertSame(number_format((float) $expected, 2, '.', ''), $row['amount']);
    }
}
