<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\PropertyUser;
use App\Support\Lookups;
use App\Support\Page;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    /** Property chooser for users with several properties. */
    public function picker(Request $request): View
    {
        $properties = PropertyUser::query()->withoutGlobalScope('property')
            ->join('properties', 'properties.id', '=', 'property_users.property_id')
            ->join('roles', 'roles.id', '=', 'property_users.role_id')
            ->leftJoin('countries', 'countries.iso2', '=', 'properties.country_iso2')
            ->where('property_users.user_id', $request->user()->id)
            ->where('property_users.status', 'active')
            ->whereNull('properties.deleted_at')
            ->orderBy('properties.name')
            ->get(['properties.code', 'properties.name', 'properties.city', 'properties.status', 'countries.name as country', 'roles.name as role'])
            ->map(fn ($p) => [
                'code' => $p->code, 'name' => $p->name, 'status' => $p->status, 'role' => $p->role,
                'location' => collect([$p->city, $p->country])->filter()->implode(', '),
                'url' => route('property.dashboard', $p->code),
            ]);

        return Page::render('onboarding/picker', ['properties' => $properties], __('property.choose_title'));
    }

    /** First property for a user who has none. */
    public function create(): View
    {
        return Page::render('onboarding/create-property', [
            'lookups' => Lookups::propertyForm(),
        ], __('property.create_title'));
    }
}
