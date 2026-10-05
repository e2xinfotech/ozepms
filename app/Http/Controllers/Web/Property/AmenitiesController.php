<?php

namespace App\Http\Controllers\Web\Property;

use App\Domain\Accommodation\Queries\AmenityQuery;
use App\Domain\Accommodation\Queries\FormOptions;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Property\Concerns\ChecksPermissions;
use App\Support\Page;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class AmenitiesController extends Controller
{
    use ChecksPermissions;

    public function index(Request $request, AmenityQuery $query, FormOptions $options): View
    {
        return Page::render('property/amenities/index', [
            'list' => $query->list($request),
            'filters' => $request->only(['q', 'category', 'tab']),
            'options' => ['categories' => $options->amenityCategories()],
            'can' => $this->can(['update' => 'rooms.update']),
        ], __('amenities.title'));
    }
}
