<?php

namespace App\Http\Controllers\WebApi\Property;

use App\Domain\Audit\AuditLogger;
use App\Domain\BookingEngine\BookingEngineService;
use App\Http\Controllers\Controller;
use App\Support\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The hotel's settings for its public booking engine (on/off, intro text, terms). */
class BookingEngineSettingsController extends Controller
{
    public function __invoke(Request $request, PropertyContext $context, BookingEngineService $engine, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'intro' => ['nullable', 'string', 'max:300'],
            'terms' => ['nullable', 'string', 'max:3000'],
        ]);
        $property = $context->property();
        $before = $engine->settings($property);
        $after = $engine->saveSettings($property, $data);
        $audit->log('booking_engine.updated', $property, ['before' => $before, 'after' => $after]);

        return response()->json(['message' => __('property.booking_engine.saved'), 'booking_engine' => $after + $engine->status($property)]);
    }
}
