<?php

namespace App\Http\Controllers\Booking\Concerns;

use App\Domain\BookingEngine\BookingEngineService;
use App\Models\Property;
use Illuminate\Http\Request;

trait ResolvesBookingProperty
{
    /** The property of a public code with an open booking engine, else 404 (no hint whether it exists). */
    protected function bookingProperty(string $code): Property
    {
        return app(BookingEngineService::class)->property($code) ?? abort(404);
    }

    /** Guest language: ?lang= (remembered), else the one chosen before, else the property's default. */
    protected function bookingLocale(Request $request, Property $property): void
    {
        $available = array_keys(config('ozepms.locales.available'));
        $lang = (string) $request->query('lang', '');
        if (in_array($lang, $available, true)) {
            $request->session()->put('locale', $lang);
        } elseif (! $request->session()->has('locale') && in_array((string) $property->default_language, $available, true)) {
            $request->session()->put('locale', (string) $property->default_language);
        }
        app()->setLocale((string) $request->session()->get('locale', config('ozepms.locales.default')));
    }
}
