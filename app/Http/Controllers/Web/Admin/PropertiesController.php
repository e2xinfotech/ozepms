<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Access\AccessService;
use App\Domain\Property\Queries\PropertyListQuery;
use App\Http\Controllers\Controller;
use App\Http\Resources\PropertyResource;
use App\Models\Property;
use App\Support\Listing;
use App\Support\Lookups;
use App\Support\Page;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** Platform property management pages (list, register, edit). */
class PropertiesController extends Controller
{
    public function index(Request $request, PropertyListQuery $properties): View
    {
        return Page::render('admin/properties/index', $properties->platform($request) + [
            'filters' => Listing::filters($request, PropertyListQuery::FILTERS),
            'selected' => (string) $request->query('selected', ''),
            'options' => [
                'countries' => $properties->countryOptions(),
                'types' => Lookups::propertyTypes(),
                'statuses' => PropertyListQuery::STATUSES,
            ],
        ], __('admin.properties_title'));
    }

    public function create(): View
    {
        return Page::render('admin/properties/create', [
            'lookups' => Lookups::propertyForm(),
            'plans' => Lookups::plans(),
            'owners' => Lookups::owners(),
            'owner_id' => (string) request()->query('owner', ''),
        ], __('admin.add_property'));
    }

    public function edit(Request $request, AccessService $access, string $code): View
    {
        $property = Property::query()->where('code', $code)->firstOrFail();

        return Page::render('admin/properties/edit', [
            'property' => (new PropertyResource($property))->resolve($request),
            'lookups' => Lookups::propertyForm(),
            'plans' => Lookups::plans(),
            'can_manage_subscription' => $access->allows($request->user(), 'platform.subscriptions.manage'),
        ], __('admin.edit_property'));
    }
}
