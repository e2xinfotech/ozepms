<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $available = array_keys(config('ozepms.locales.available'));
        $locale = $request->user()?->locale
            ?? $request->session()->get('locale')
            ?? $request->getPreferredLanguage($available)
            ?? config('ozepms.locales.default');

        app()->setLocale(in_array($locale, $available, true) ? $locale : config('ozepms.locales.default'));

        return $next($request);
    }
}
