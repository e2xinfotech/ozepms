<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\BookingEngine\BookingEngineService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\SearchRequest;
use App\Support\PropertyContext;
use Illuminate\Http\JsonResponse;

/** GET /api/v1/availability — bookable room types and rate plans with offers, taxes and terms (same search as the booking page). */
class AvailabilityController extends Controller
{
    public function __invoke(SearchRequest $request, PropertyContext $context, BookingEngineService $engine): JsonResponse
    {
        return response()->json(['data' => $engine->search($context->property(), $request->validated() + ['children' => 0, 'infants' => 0])]);
    }
}
