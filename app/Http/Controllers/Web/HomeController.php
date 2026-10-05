<?php

namespace App\Http\Controllers\Web;

use App\Domain\Access\AccessService;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertyUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function root(Request $request): RedirectResponse
    {
        return $request->user() ? redirect()->route('home') : redirect()->route('login');
    }

    /** Decides where a signed-in user lands. */
    public function home(Request $request, AccessService $access): RedirectResponse
    {
        $user = $request->user();

        $codes = PropertyUser::query()->withoutGlobalScope('property')
            ->join('properties', 'properties.id', '=', 'property_users.property_id')
            ->where('property_users.user_id', $user->id)
            ->where('property_users.status', 'active')
            ->whereNull('properties.deleted_at')
            ->whereIn('properties.status', ['onboarding', 'active'])
            ->pluck('properties.code', 'properties.id');

        if ($user->last_property_id && $codes->has($user->last_property_id)) {
            return redirect()->route('property.dashboard', $codes[$user->last_property_id]);
        }

        if (in_array('platform.dashboard', $access->platformPermissions($user), true)) {
            return redirect()->route('admin.dashboard');
        }

        return match ($codes->count()) {
            0 => redirect()->route('properties.create'),
            1 => redirect()->route('property.dashboard', $codes->first()),
            default => redirect()->route('properties.index'),
        };
    }
}
