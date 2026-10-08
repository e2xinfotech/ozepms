<?php

namespace App\Http\Middleware;

use App\Domain\Platform\ImpersonationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While someone acts as another user: ends the session when its time is up and refuses
 * account-security actions (password, two-factor, profile) that must stay with the real owner.
 */
class GuardImpersonation
{
    /** Routes that change how a person signs in. */
    private const BLOCKED = ['webapi.account.two-factor.*', 'webapi.account.password', 'webapi.account.profile', 'webapi.admin.users.password-link', 'webapi.property.users.password-link'];

    public function __construct(private readonly ImpersonationService $impersonation) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! $request->session()->has(ImpersonationService::SESSION_KEY)) {
            return $next($request);
        }

        $state = $this->impersonation->state($request);
        if ($state === null) {
            // The session was left behind by a sign-in as someone else; it no longer applies.
            $request->session()->forget(ImpersonationService::SESSION_KEY);

            return $next($request);
        }

        if (now()->timestamp >= $state['expires_at']) {
            $this->impersonation->stop($request, 'impersonation.expired');
            $to = $request->user() ? route('admin.dashboard') : route('login');

            return $request->expectsJson() || $request->is('web-api/*')
                ? response()->json(['error' => ['code' => 'IMPERSONATION_EXPIRED', 'message' => __('impersonation.expired')], 'redirect' => $to], 409)
                : redirect($to)->with('notice', __('impersonation.expired'));
        }

        if ($request->routeIs(...self::BLOCKED)) {
            return response()->json(['error' => ['code' => 'IMPERSONATION_BLOCKED', 'message' => __('impersonation.blocked')]], 403);
        }

        return $next($request);
    }
}
