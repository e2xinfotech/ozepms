<?php

namespace App\Http\Middleware;

use App\Domain\Subscription\SubscriptionService;
use App\Support\PropertyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Expired or suspended subscriptions keep read access but block every change.
 */
class EnsureSubscriptionActive
{
    public function __construct(
        private readonly PropertyContext $context,
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->context->has() || $request->isMethodSafe() || $this->context->isSupportMode()) {
            return $next($request);
        }

        $state = $this->subscriptions->state($this->context->property());

        if ($state['read_only']) {
            $message = __('subscription.read_only');

            return $request->expectsJson()
                ? response()->json(['error' => ['code' => 'SUBSCRIPTION_INACTIVE', 'message' => $message]], 402)
                : back()->withErrors(['subscription' => $message]);
        }

        return $next($request);
    }
}
