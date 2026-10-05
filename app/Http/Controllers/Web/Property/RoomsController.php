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
            ],
            'can' => $this->can(['create' => 'rooms.create', 'update' => 'rooms.update', 'housekeeping' => 'housekeeping.update']),
        ], __('nav.rooms'));
    }
}
