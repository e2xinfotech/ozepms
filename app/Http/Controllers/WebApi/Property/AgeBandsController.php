<?php

namespace App\Http\Controllers\WebApi\Property;

use App\Domain\Property\AgeBandService;
use App\Http\Controllers\Controller;
use App\Support\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Settings → guest age groups (infants, children). */
class AgeBandsController extends Controller
{
    public function __invoke(Request $request, PropertyContext $context, AgeBandService $service): JsonResponse
    {
        $data = $request->validate(['infant_max' => ['required', 'integer', 'min:0', 'max:5'], 'child_max' => ['required', 'integer', 'min:1', 'max:17']]);
        $bands = $service->save($context->property(), (int) $data['infant_max'], (int) $data['child_max'], $request->user());

        return response()->json(['message' => __('property.age_bands.saved'), 'age_bands' => $bands]);
    }
}
