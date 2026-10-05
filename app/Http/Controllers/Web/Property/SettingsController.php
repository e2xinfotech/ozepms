<?php

namespace App\Http\Controllers\Web\Property;

use App\Domain\Access\AccessService;
use App\Http\Controllers\Controller;
use App\Http\Resources\PropertyResource;
use App\Support\Lookups;
use App\Support\Page;
use App\Support\PropertyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    /** Property Configuration form; read-only for users without property.update. */
    public function edit(Request $request, PropertyContext $context, AccessService $access): View
    {
        return Page::render('property/settings/index', [
            'property' => (new PropertyResource($context->property()))->resolve($request),
            'lookups' => Lookups::propertyForm(),
            'can_update' => $access->allows($request->user(), 'property.update'),
        ], __('property.settings_title'));
    }
}
