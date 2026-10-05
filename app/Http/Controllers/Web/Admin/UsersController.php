<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Users\Queries\PlatformUserQuery;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\Role;
use App\Support\Listing;
use App\Support\Page;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** Every account on the platform (Super Admin → Users & Roles). */
class UsersController extends Controller
{
    private const PROPERTY_OPTIONS_LIMIT = 300;

    public function index(Request $request, PlatformUserQuery $users): View
    {
        $roles = Role::query()->whereNull('property_id')->orderByDesc('scope')->orderBy('name')
            ->get(['code', 'name', 'scope', 'color', 'description']);

        return Page::render('admin/users/index', $users->list($request) + [
            'filters' => Listing::filters($request, ['q', 'role', 'property', 'status', 'kind']),
            'selected' => (string) $request->query('selected', ''),
            'options' => [
                'roles' => $roles->map(fn (Role $r) => ['value' => $r->code, 'label' => $r->name])->values()->all(),
                'platform_roles' => $roles->where('scope', 'platform')->map(fn (Role $r) => [
                    'value' => $r->code,
                    'label' => $r->name,
                    'description' => $r->description && str_starts_with($r->description, 'roles.descriptions.') ? __($r->description) : $r->description,
                ])->values()->all(),
                'properties' => Property::query()->orderBy('name')->limit(self::PROPERTY_OPTIONS_LIMIT)
                    ->get(['code', 'name'])->map(fn (Property $p) => ['value' => $p->code, 'label' => $p->name.' ('.$p->code.')'])->all(),
                'statuses' => PlatformUserQuery::STATUSES,
            ],
            'locales' => config('ozepms.locales.available'),
            'me' => $request->user()->public_id,
        ], __('users.title'));
    }
}
