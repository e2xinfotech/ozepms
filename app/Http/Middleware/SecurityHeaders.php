<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        Vite::useCspNonce();

        $response = $next($request);

        if (function_exists('header_remove')) {
            header_remove('X-Powered-By');
        }
        $response->headers->remove('X-Powered-By');

        $nonce = Vite::cspNonce();
        // The Vite dev server is allowed only while it runs (public/hot exists). Browsers reject
        // IPv6 literals in CSP source lists, so localhost is used for both address families.
        $devServer = app()->isLocal() && Vite::isRunningHot() ? ' http://localhost:5173 ws://localhost:5173' : '';

        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'".$devServer,
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com".$devServer,
            "font-src 'self' https://fonts.gstatic.com data:",
            "img-src 'self' data: blob:",
            "connect-src 'self'".$devServer,
            "frame-ancestors 'none'",
            "form-action 'self'",
            "base-uri 'self'",
            "object-src 'none'",
        ]);

        $response->headers->set('Content-Security-Policy', $csp);
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age='.config('ozepms.security.hsts_max_age').'; includeSubDomains; preload');
        }

        if ($request->user()) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
