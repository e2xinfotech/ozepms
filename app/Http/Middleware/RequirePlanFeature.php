<?php

namespace App\Http\Middleware;

use App\Support\PropertyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route guard for modules sold per subscription plan: ->middleware('plan.feature:reports').
 * The current property's plan must include the feature (SubscriptionPlan::features). Platform
 * staff in support mode are not limited, so they can see what the hotel would get.
 */
class RequirePlanFeature
{
    public function __construct(private readonly PropertyContext $context) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if ($this->context->has() && ! $this->context->isSupportMode()) {
            $plan = $this->context->property()->currentSubscription?->plan;
            abort_unless((bool) (($plan?->features ?? [])[$feature] ?? false), 403, __('errors.plan_feature'));
        }

        return $next($request);
    }
}
