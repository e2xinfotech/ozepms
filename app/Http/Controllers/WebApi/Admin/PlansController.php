<?php

namespace App\Http\Controllers\WebApi\Admin;

use App\Domain\Subscription\PlanService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SavePlanRequest;
use App\Http\Resources\PlanResource;
use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;

class PlansController extends Controller
{
    public function __construct(private readonly PlanService $plans) {}

    public function store(SavePlanRequest $request): JsonResponse
    {
        $plan = $this->plans->create($request->planData(), $request->user());

        return response()->json(['message' => $plan->approval_status === 'pending' ? __('approvals.plan_submitted') : __('ui.created'), 'plan' => (new PlanResource($plan))->resolve($request)], 201);
    }

    public function update(SavePlanRequest $request, string $plan): JsonResponse
    {
        $model = $this->plans->update(SubscriptionPlan::query()->where('code', $plan)->firstOrFail(), $request->planData(), $request->user());

        return response()->json(['message' => __('ui.saved'), 'plan' => (new PlanResource($model))->resolve($request)]);
    }
}
