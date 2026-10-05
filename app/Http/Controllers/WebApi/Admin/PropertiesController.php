<?php

namespace App\Http\Controllers\WebApi\Admin;

use App\Domain\Property\PropertyService;
use App\Domain\Subscription\SubscriptionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignSubscriptionRequest;
use App\Http\Requests\Admin\ChangePropertyStatusRequest;
use App\Http\Requests\Admin\StorePropertyRequest;
use App\Http\Requests\Admin\UpdatePropertyRequest;
use App\Http\Resources\PropertyResource;
use App\Models\Property;
use App\Models\SubscriptionPlan;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Platform management of any property (E2X staff with platform.properties.manage). */
class PropertiesController extends Controller
{
    public function __construct(private readonly PropertyService $properties) {}

    public function show(Request $request, string $code): JsonResponse
    {
        return response()->json(['property' => (new PropertyResource($this->find($code)))->resolve($request)]);
    }

    public function store(StorePropertyRequest $request): JsonResponse
    {
        $planId = $request->validated('plan_id');
        $property = $this->properties->register(
            $request->propertyFields(),
            $request->owner(),
            $planId ? SubscriptionPlan::query()->find($planId) : null,
            $request->user(),
        );

        $request->session()->flash('success', __('property.created'));

        return response()->json([
            'message' => __('property.created'),
            'code' => $property->code,
            'redirect' => route('admin.properties', ['selected' => $property->code]),
        ], 201);
    }

    public function update(UpdatePropertyRequest $request, string $code): JsonResponse
    {
        $property = $this->properties->update($this->find($code), $request->propertyFields());

        return response()->json([
            'message' => __('ui.saved'),
            'property' => (new PropertyResource($property->refresh()))->resolve($request),
        ]);
    }

    public function status(ChangePropertyStatusRequest $request, string $code): JsonResponse
    {
        $property = $this->properties->changeStatus($this->find($code), (string) $request->validated('status'), $request->validated('reason'));

        return response()->json(['message' => __('property.status_changed'), 'status' => $property->status]);
    }

    public function subscription(AssignSubscriptionRequest $request, SubscriptionService $subscriptions, string $code): JsonResponse
    {
        $property = $this->find($code);
        $subscriptions->assign(
            $property,
            SubscriptionPlan::query()->findOrFail($request->validated('plan_id')),
            CarbonImmutable::createFromFormat('Y-m-d', (string) $request->validated('starts_on'))->startOfDay(),
            CarbonImmutable::createFromFormat('Y-m-d', (string) $request->validated('ends_on'))->startOfDay(),
            $request->validated('price'),
            $request->user(),
            $request->validated('notes'),
        );

        return response()->json(['message' => __('subscription.assigned'), 'subscription' => $subscriptions->state($property->refresh())]);
    }

    private function find(string $code): Property
    {
        return Property::query()->where('code', $code)->firstOrFail();
    }
}
