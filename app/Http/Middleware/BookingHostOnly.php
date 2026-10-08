<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** On the booking engine's own host only the booking engine answers; PMS and platform screens are not reachable there. */
class BookingHostOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $domain = config('ozepms.booking_engine.domain');
        if ($domain && strcasecmp($request->getHost(), $domain) === 0) {
            // Routes are matched later, so decide by path: the engine's own paths are /{code}, /{code}/checkout, /{code}/booking/..., /{code}/api/...
            if ($request->is('web-api/*', 'admin', 'admin/*', 'p/*', 'login', 'hooks/*', 'api/*', 'account/*', 'properties', 'properties/*', 'home')) {
                abort(404);
            }
        }

        return $next($request);
    }
}
