<?php

namespace App\Http\Controllers\Web\Property;

use App\Domain\Accommodation\Queries\FormOptions;
use App\Domain\Accommodation\Queries\PhysicalUnitQuery;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Property\Concerns\ChecksPermissions;
use App\Support\Page;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class RoomsController extends Controller
{
    private const FACILITY_CATEGORIES = ['property', 'service'];

    use ChecksPermissions;

    public function index(Request $request, PhysicalUnitQuery $query, FormOptions $options): View
    {
        return Page::render('property/rooms/index', [
            'list' => $query->list($request),
            'filters' => $request->only(['q', 'room_type', 'status', 'floor', 'tab', 'sort', 'dir', 'selected']),
            'options' => [
                'room_types' => $options->roomTypes(),
                'floors' => $options->floors(),
                'block_types' => $options->blockTypes(),
                'usage' => $options->usage(),
                // A single room only takes in-room amenities; property-wide facilities are set on the Amenities page.
                'amenities' => array_values(array_filter($options->amenities(), fn ($a) => ! in_array($a['category'], self::FACILITY_CATEGORIES, true))),
                'amenity_categories' => array_values(array_filter($options->amenityCategories(), fn ($c) => ! in_array($c['value'], self::FACILITY_CATEGORIES, true))),
                'room_type_amenities' => $options->roomTypeAmenities(),
            ],
            'can' => $this->can(['create' => 'rooms.create', 'update' => 'rooms.update', 'housekeeping' => 'housekeeping.update']),
        ], __('nav.rooms'));
    }
}
