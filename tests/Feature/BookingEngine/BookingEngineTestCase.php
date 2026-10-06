<?php

namespace Tests\Feature\BookingEngine;

use App\Domain\BookingEngine\BookingEngineService;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Offers\OfferTestCase;

/** The main property on a plan that includes the booking engine; DLX (3 rooms) and STE (1 room) on BAR. */
abstract class BookingEngineTestCase extends OfferTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pro = SubscriptionPlan::query()->where('code', 'professional')->firstOrFail();
        DB::table('subscriptions')->where('property_id', $this->property->id)->update(['plan_id' => $pro->id]);
        Cache::flush();
    }

    protected function engine(): BookingEngineService
    {
        return app(BookingEngineService::class);
    }

    protected function stayQuery(int $in = 5, int $out = 7, array $extra = []): array
    {
        return array_merge(['check_in' => $this->day($in)->toDateString(), 'check_out' => $this->day($out)->toDateString(), 'adults' => 2, 'children' => 0, 'infants' => 0], $extra);
    }

    protected function guest(array $extra = []): array
    {
        return array_merge(['first_name' => 'Maya', 'last_name' => 'Iyer', 'email' => 'maya@example.com', 'phone' => '+91 98765 43210'], $extra);
    }
}
