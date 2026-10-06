<?php

namespace App\Http\Controllers\Web\Property;

use App\Domain\Property\DashboardService;
use App\Domain\Property\OnboardingService;
use App\Http\Controllers\Controller;
use App\Support\Page;
use App\Support\PropertyContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /** Property dashboard; ?from=&to= (Y-m-d) choose the chart and revenue range. */
    public function __invoke(Request $request, PropertyContext $context, DashboardService $dashboard, OnboardingService $onboarding): View
    {
        // Catches up properties whose setup was finished outside the usual pages.
        $property = $onboarding->sync($context->property());

        return Page::render('property/dashboard/index', [
            'dashboard' => $dashboard->build($property, $this->date($request, 'from'), $this->date($request, 'to')),
            'max_range_days' => DashboardService::MAX_RANGE_DAYS,
            'onboarding' => [
                'in_setup' => $property->status === 'onboarding',
                'next_url' => $onboarding->nextUrl($property),
            ],
        ], __('nav.dashboard'));
    }

    private function date(Request $request, string $key): ?CarbonImmutable
    {
        $value = (string) $request->query($key, '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
