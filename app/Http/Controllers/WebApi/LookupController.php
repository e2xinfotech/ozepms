<?php

namespace App\Http\Controllers\WebApi;

use App\Http\Controllers\Controller;
use App\Models\State;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class LookupController extends Controller
{
    /** States / provinces of one country for the property form. */
    public function states(Request $request): JsonResponse
    {
        $country = strtoupper((string) $request->query('country', ''));
        if (! preg_match('/^[A-Z]{2}$/', $country)) {
            return response()->json(['states' => []]);
        }

        $states = Cache::remember('lookups:states:'.$country, 86400, fn () => State::query()
            ->where('country_iso2', $country)->orderBy('name')->get(['id', 'name'])
            ->map(fn (State $s) => ['value' => $s->id, 'label' => $s->name])->all());

        return response()->json(['states' => $states]);
    }
}
