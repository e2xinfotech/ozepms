<?php

namespace App\Http\Middleware;

use App\Domain\Auth\TwoFactorService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Sends users who must use two-factor authentication to the setup page until they do. */
class EnsureTwoFactorEnrolled
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->hasTwoFactorEnabled() && $this->twoFactor->isRequiredFor($user)) {
            if ($request->expectsJson()) {
                return response()->json(['error' => ['code' => 'TWO_FACTOR_REQUIRED', 'message' => __('auth.two_factor_required')]], 403);
            }

            return redirect()->route('account.security')->with('notice', __('auth.two_factor_required'));
        }

        return $next($request);
    }
}
