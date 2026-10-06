<?php

namespace App\Http\Controllers\Web\Property;

use App\Domain\Accommodation\Queries\FormOptions;
use App\Domain\Inventory\Queries\CalendarQuery;
use App\Domain\Inventory\Queries\CalendarYearQuery;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Property\Concerns\ChecksPermissions;
use App\Models\PhysicalUnit;
use App\Support\Page;
use App\Support\PropertyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** Rates & availability calendar (designs: calendar-ari.png, calendar-view-toggle.png, calendar-reservation-bars.png). */
class CalendarController extends Controller
{
    use ChecksPermissions;

    public function index(Request $request, CalendarQuery $query, CalendarYearQuery $yearQuery, FormOptions $options, PropertyContext $context): View
    {
        $property = $context->property();
        // Query-string driven; unknown values fall back to the defaults instead of failing the page.
        $range = in_array($request->query('range'), [...CalendarQuery::RANGES, 'year'], true) ? (string) $request->query('range') : 'month';
        $date = is_string($request->query('from')) ? $request->query('from') : null;
        $view = $request->query('view') === 'reservations' ? 'reservations' : 'inventory';
        $filters = CalendarQuery::normalizeFilters($request->query());
        if ($range === 'year') {
            $from = CalendarYearQuery::resolveStart($date, $property);
            $grid = null;
            $year = $yearQuery->year($property, $from, $filters);
        } else {
            [$from, $days] = CalendarQuery::resolveWindow($range, $date, $property);
            $grid = $query->window($property, $from, $days, $filters);
            $year = null;
        }

        return Page::render('property/calendar/index', [
            'grid' => $grid,
            'year' => $year,
            'filters' => ['range' => $range, 'view' => $view, 'from' => $from->toDateString()] + $filters,
            'options' => [
                'room_types' => $options->roomTypes(),
                'rate_plans' => $options->ratePlans(),
                'units' => PhysicalUnit::query()->with('roomType:id,code')->orderBy('sort_order')->orderBy('name')
                    ->get(['id', 'public_id', 'room_type_id', 'name', 'is_active'])
                    ->map(fn (PhysicalUnit $u) => ['value' => $u->public_id, 'label' => $u->name, 'group' => $u->roomType?->code])->all(),
                'statuses' => array_map(fn (string $s) => ['value' => $s, 'label' => __('calendar.status.'.$s)], ['all', 'inactive']),
                'availability' => array_map(fn (string $s) => ['value' => $s, 'label' => __('calendar.availability.'.$s)], CalendarQuery::AVAILABILITY),
                'restrictions' => array_map(fn (string $s) => ['value' => $s, 'label' => __('calendar.restrictions.'.$s)], CalendarQuery::RESTRICTIONS),
            ],
            'can' => $this->can(['update' => 'calendar.update', 'reservations' => 'reservations.view']),
        ], __('calendar.title'));
    }
}
