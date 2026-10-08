<?php

namespace App\Http\Controllers\WebApi;

use App\Domain\Access\AccessService;
use App\Domain\Platform\ApprovalService;
use App\Domain\Property\OnboardingService;
use App\Domain\Property\PropertyService;
use App\Domain\Subscription\SubscriptionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Property\StorePropertyRequest;
use Illuminate\Http\JsonResponse;

class OnboardingController extends Controller
{
    /** The signed-in user registers a property and becomes its owner, on a trial of the default plan. */
    public function store(StorePropertyRequest $request, PropertyService $properties, SubscriptionService $subscriptions, OnboardingService $onboarding, AccessService $access, ApprovalService $approvals): JsonResponse
    {
        $user = $request->user();
        // Platform staff register properties without a wait; anyone else waits for approval.
        $needsApproval = ! $access->allows($user, 'platform.properties.manage');
        $property = $properties->createWithLanguages(
            $request->propertyFields() + ['status' => $needsApproval ? 'pending_approval' : 'onboarding'],
            $user,
            $subscriptions->defaultPlan(),
            $user,
        );

        if ($needsApproval) {
            $approvals->request('property_registration', $property, $user,
                __('approvals.property_summary', ['name' => $property->name, 'code' => $property->code, 'owner' => $user->email]), $property->id);

            return response()->json(['code' => $property->code, 'redirect' => route('property.pending', $property->code)], 201);
        }

        $request->session()->flash('success', __('property.created'));

        // Straight on to the next setup step (rate plan, then room types) when those pages exist.
        return response()->json([
            'code' => $property->code,
            'redirect' => $onboarding->nextUrl($property) ?? route('property.dashboard', $property->code),
        ], 201);
    }
}
