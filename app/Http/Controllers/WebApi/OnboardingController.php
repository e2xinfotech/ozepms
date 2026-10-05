<?php

namespace App\Http\Controllers\WebApi;

use App\Domain\Property\PropertyService;
use App\Domain\Subscription\SubscriptionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Property\StorePropertyRequest;
use Illuminate\Http\JsonResponse;

class OnboardingController extends Controller
{
    /** The signed-in user registers a property and becomes its owner, on a trial of the default plan. */
    public function store(StorePropertyRequest $request, PropertyService $properties, SubscriptionService $subscriptions): JsonResponse
    {
        $user = $request->user();
        $property = $properties->createWithLanguages(
            $request->propertyFields() + ['status' => 'onboarding'],
            $user,
            $subscriptions->defaultPlan(),
            $user,
        );

        $request->session()->flash('success', __('property.created'));

        return response()->json([
            'code' => $property->code,
            'redirect' => route('property.dashboard', $property->code),
        ], 201);
    }
}
