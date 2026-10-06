<?php

namespace App\Http\Controllers\WebApi\Property;

use App\Domain\Access\AccessService;
use App\Domain\Reservations\Queries\GlobalSearch;
use App\Http\Controllers\Controller;
use App\Support\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Top bar search: reservations, guests and PMS rooms the user may see. */
class SearchController extends Controller
{
    public function __invoke(Request $request, GlobalSearch $search, AccessService $access, PropertyContext $context): JsonResponse
    {
        $q = (string) $request->validate(['q' => ['required', 'string', 'max:100']])['q'];
        $user = $request->user();
        $result = $search->search($context->property(), $q,
            reservations: $access->allows($user, 'reservations.view'),
            guests: $access->allows($user, 'guests.view'));
        if (! $access->allows($user, 'rooms.view') && ! $access->allows($user, 'reservations.view')) {
            $result['rooms'] = [];
        }

        return response()->json($result);
    }
}
