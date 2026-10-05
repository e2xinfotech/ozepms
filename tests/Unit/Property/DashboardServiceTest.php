<?php

namespace Tests\Unit\Property;

use App\Domain\Property\DashboardService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class DashboardServiceTest extends TestCase
{
    public function test_new_property_reports_zeros_not_invented_figures(): void
    {
        [$property] = $this->createPropertyWithOwner();

        $d = app(DashboardService::class)->build($property);

        $this->assertSame(0, $d['kpis']['total_rooms']);
        $this->assertSame(0, $d['kpis']['occupied']);
        $this->assertEquals(0, $d['kpis']['occupancy']);
        $this->assertSame(0, $d['kpis']['arrivals']);
        $this->assertSame(0, $d['kpis']['departures']);
        $this->assertSame('0.00', $d['kpis']['revenue']);
        $this->assertSame([], $d['arrivals']);
        $this->assertSame([], $d['departures']);
        $this->assertSame(['occupied' => 0, 'vacant' => 0, 'out_of_service' => 0, 'blocked' => 0, 'total' => 0], $d['room_status']);
        $this->assertCount(14, $d['chart']);
        foreach ($d['chart'] as $day) {
            $this->assertEquals(0, $day['occupancy']);
            $this->assertSame('0.00', $day['revenue']);
        }
        $this->assertTrue($d['checklist'][0]['done']);
    }

    public function test_chart_follows_the_requested_range(): void
    {
        [$property] = $this->createPropertyWithOwner();
        $from = CarbonImmutable::parse('2026-10-01');

        $d = app(DashboardService::class)->build($property, $from, $from->addDays(9));

        $this->assertSame('2026-10-01', $d['from']);
        $this->assertSame('2026-10-10', $d['to']);
        $this->assertCount(10, $d['chart']);
        $this->assertSame('2026-10-10', end($d['chart'])['date']);
    }

    public function test_invalid_or_too_long_ranges_fall_back_to_the_default(): void
    {
        [$property] = $this->createPropertyWithOwner();
        $service = app(DashboardService::class);
        $from = CarbonImmutable::parse('2026-01-01');

        $this->assertCount(14, $service->build($property, $from, $from->addDays(DashboardService::MAX_RANGE_DAYS + 1))['chart']);
        $this->assertCount(14, $service->build($property, $from, $from->subDay())['chart']);
    }

    public function test_dashboard_page_reads_the_range_from_the_query_string(): void
    {
        [$property, $owner] = $this->createPropertyWithOwner();

        $this->actingAs($owner)->get("/p/{$property->code}/dashboard?from=2026-10-01&to=2026-10-07")
            ->assertOk()->assertSee('"from":"2026-10-01","to":"2026-10-07"', false);
        $this->actingAs($owner)->get("/p/{$property->code}/dashboard?from=junk&to=2026-13-45")->assertOk();
    }
}
