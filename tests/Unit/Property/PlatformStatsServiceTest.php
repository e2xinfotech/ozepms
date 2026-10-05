<?php

namespace Tests\Unit\Property;

use App\Domain\Platform\PlatformStatsService;
use Tests\TestCase;

class PlatformStatsServiceTest extends TestCase
{
    public function test_summary_counts_properties_by_status(): void
    {
        $this->createPropertyWithOwner(['status' => 'active']);
        $this->createPropertyWithOwner(['status' => 'active']);
        [$setup] = $this->createPropertyWithOwner(['status' => 'onboarding']);
        [$off] = $this->createPropertyWithOwner();
        $off->forceFill(['status' => 'inactive'])->save();

        $s = app(PlatformStatsService::class)->summary();

        $this->assertSame(4, $s['properties']);
        $this->assertSame(2, $s['properties_active']);
        $this->assertSame(1, $s['properties_inactive']);
        $this->assertSame(1, $s['properties_onboarding']);
        $this->assertSame(0, $s['rooms']);
        $this->assertSame(0, $s['bookings_month']);
        $this->assertNull($s['revenue_month']);
    }

    public function test_booking_performance_has_one_point_per_day(): void
    {
        $points = app(PlatformStatsService::class)->bookingPerformance(7);

        $this->assertCount(7, $points);
        $this->assertSame(now()->toDateString(), end($points)['date']);
        $this->assertSame(0, array_sum(array_column($points, 'bookings')));
    }

    public function test_admin_properties_page_filters_by_status_and_search(): void
    {
        $this->createPropertyWithOwner(['name' => 'Alpha Lodge', 'status' => 'active']);
        $this->createPropertyWithOwner(['name' => 'Beta Lodge', 'status' => 'onboarding']);
        $admin = $this->makePlatformAdmin();

        $this->actingAs($admin)->get('/admin/properties?status=onboarding')->assertOk()->assertSee('Beta Lodge')->assertDontSee('Alpha Lodge');
        $this->actingAs($admin)->get('/admin/properties?q=Alpha')->assertOk()->assertSee('Alpha Lodge')->assertDontSee('Beta Lodge');
    }
}
