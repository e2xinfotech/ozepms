<?php

namespace App\Http\Controllers\Web\Property;

use App\Domain\Accommodation\Queries\FormOptions;
use App\Domain\Inventory\Queries\CalendarQuery;
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

    public function index(Request $request, CalendarQuery $query, FormOptions $options, PropertyContext $context): View
    {
        $property = $context->property();
        // Query-string driven; unknown values fall back to the defaults instead of failing the page.
        $range = in_array($request->query('range'), CalendarQuery::RANGES, true) ? (string) $request->query('range') : 'month';
        $from = is_string($request->query('from')) ? $request->query('from') : null;
        [$from, $days] = CalendarQuery::resolveWindow($range, $from, $property);
        $view = $request->query('view') === 'reservations' ? 'reservations' : 'inventory';
        $id = fn (string $key) => is_string($request->query($key)) && preg_match('/^[0-9A-Za-z]{1,26}$/', $request->query($key)) ? $request->query($key) : null;
        $filters = [
            'room_type' => $id('room_type'),
            'unit' => $id('unit'),
            'rate_plan' => $id('rate_plan'),
            'status' => in_array($request->query('status'), CalendarQuery::STATUSES, true) ? $request->query('status') : null,
        ];

        return Page::render('property/calendar/index', [
            'grid' => $query->window($property, $from, $days, $filters),
            'filters' => ['range' => $range, 'view' => $view, 'from' => $from->toDateString()] + $filters,
            'options' => [
                'room_types' => $options->roomTypes(),
                'rate_plans' => $options->ratePlans(),
                'units' => PhysicalUnit::query()->with('roomType:id,code')->orderBy('sort_order')->orderBy('name')
                    ->get(['id', 'public_id', 'room_type_id', 'name', 'is_active'])
                    ->map(fn (PhysicalUnit $u) => ['value' => $u->public_id, 'label' => $u->name, 'group' => $u->roomType?->code])->all(),
                'statuses' => array_map(fn (string $s) => ['value' => $s, 'label' => __('calendar.status.'.$s)], CalendarQuery::STATUSES),
            ],
            'can' => $this->can(['update' => 'calendar.update', 'reservations' => 'reservations.view']),
        ], __('calendar.title'));
    }
}
