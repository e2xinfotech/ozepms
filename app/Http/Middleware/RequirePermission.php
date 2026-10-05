<?php

namespace App\Http\Middleware;

use App\Domain\Access\AccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route guard: ->middleware('can.do:reservations.create') or several keys separated by "|"
 * (any one of them is enough).
 */
class RequirePermission
{
    public function __construct(private readonly AccessService $access) {}

    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $user = $request->user();

        foreach (explode('|', $permissions) as $permission) {
            if ($user && $this->access->allows($user, $permission)) {
                return $next($request);
            }
        }

        abort(403);
    }
}
