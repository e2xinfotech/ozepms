<?php

namespace App\Http\Controllers\Web\Property;

use App\Domain\Access\AccessService;
use App\Domain\Property\Queries\PropertyListQuery;
use App\Http\Controllers\Controller;
use App\Support\Listing;
use App\Support\Lookups;
use App\Support\Page;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class PropertiesController extends Controller
{
    /** The properties the signed-in user belongs to, with a detail panel (?selected=P1001). */
    public function index(Request $request, PropertyListQuery $properties, AccessService $access): View
    {
        $userId = (int) $request->user()->id;

        return Page::render('property/properties/index', $properties->forUser($userId, $request) + [
            'filters' => Listing::filters($request, PropertyListQuery::FILTERS),
            'selected' => (string) $request->query('selected', ''),
            'options' => [
                'countries' => $properties->countryOptions($userId),
                'types' => Lookups::propertyTypes(),
                'statuses' => PropertyListQuery::STATUSES,
            ],
            'can_create' => true,
            'can_copy' => $access->allows($request->user(), 'property.update'),
        ], __('nav.properties'));
    }
}
