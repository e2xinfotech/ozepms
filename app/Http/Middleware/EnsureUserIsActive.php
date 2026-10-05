<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Signs out users who were disabled while they had an open session. */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->isActive()) {
            Auth::logout();
            $request->session()->invalidate();

            return $request->expectsJson()
                ? response()->json(['error' => ['code' => 'ACCOUNT_DISABLED', 'message' => __('auth.disabled')]], 401)
                : redirect()->route('login')->withErrors(['email' => __('auth.disabled')]);
        }

        return $next($request);
    }
}
