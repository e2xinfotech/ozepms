<?php

namespace App\Http\Controllers\Web\Property;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Users\Queries\PropertyUserQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Property\SaveRoleRequest;
use App\Support\Listing;
use App\Support\Page;
use App\Support\PropertyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class UsersController extends Controller
{
    /** Users & Roles of the current property; ?selected=<user public id> opens the side panel. */
    public function index(Request $request, PropertyContext $context, PropertyUserQuery $users): View
    {
        return Page::render('property/users/index', $users->list($request) + [
            'filters' => Listing::filters($request, ['q', 'role', 'status']),
            'selected' => (string) $request->query('selected', ''),
            'roles' => PermissionCatalogue::rolesFor($context->id()),
            'catalogue' => PermissionCatalogue::grouped('property'),
            'colors' => SaveRoleRequest::COLORS,
            'locales' => config('ozepms.locales.available'),
            'me' => $request->user()->public_id,
        ], __('users.title'));
    }
}
