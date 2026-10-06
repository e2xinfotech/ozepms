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
        $devServer = app()->isLocal() && Vite::isRunningHot() ? $this->devServerSources() : '';
        // Razorpay Checkout (script + payment window) only when online payments are configured.
        $gateway = config('ozepms.billing.razorpay.key_id') && config('ozepms.billing.razorpay.key_secret');
        $rzpScript = $gateway ? ' https://checkout.razorpay.com' : '';
        $rzpFrame = $gateway ? "frame-src 'self' https://api.razorpay.com https://checkout.razorpay.com" : "frame-src 'self'";
        $rzpConnect = $gateway ? ' https://api.razorpay.com https://lumberjack.razorpay.com' : '';

        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'".$rzpScript.$devServer,
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com".$devServer,
            "font-src 'self' https://fonts.gstatic.com data:",
            "img-src 'self' data: blob:",
            "connect-src 'self'".$rzpConnect.$devServer,
            $rzpFrame,
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

    /**
     * Sources of the Vite dev server, taken from public/hot (written by the dev server while it runs).
     * Browsers reject IPv6 literals in CSP source lists, so [::1] is replaced by localhost.
     */
    private function devServerSources(): string
    {
        $url = trim((string) @file_get_contents(Vite::hotFile()));
        $parts = parse_url($url) ?: [];
        $host = $parts['host'] ?? 'localhost';
        if (str_contains($host, ':') || str_starts_with($host, '[')) {
            $host = 'localhost';
        }
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $scheme = $parts['scheme'] ?? 'http';
        $ws = $scheme === 'https' ? 'wss' : 'ws';

        return " {$scheme}://{$host}{$port} {$ws}://{$host}{$port}";
    }
}
