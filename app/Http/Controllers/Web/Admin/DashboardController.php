<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Platform\PlatformStatsService;
use App\Http\Controllers\Controller;
use App\Support\Page;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    private const PERIODS = ['7' => 7, '14' => 14, '30' => 30];

    /** Super Admin dashboard; ?days=7|14|30 sets the booking chart period. */
    public function __invoke(Request $request, PlatformStatsService $stats): View
    {
        $days = self::PERIODS[(string) $request->query('days', '14')] ?? 14;

        return Page::render('admin/dashboard/index', [
            'summary' => $stats->summary(),
            'distribution' => $stats->distributionByType(),
            'activity' => $stats->recentActivity(),
            'system' => $stats->systemOverview(),
            'bookings' => $stats->bookingPerformance($days),
            'days' => $days,
            'periods' => array_values(self::PERIODS),
            'properties' => $stats->propertiesOverview(),
        ], __('admin.dashboard_title'));
    }
}
