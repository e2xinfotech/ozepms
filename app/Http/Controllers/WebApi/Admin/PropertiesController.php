<?php

namespace App\Http\Controllers\WebApi\Admin;

use App\Domain\Property\PropertyCopyService;
use App\Domain\Property\PropertyMediaService;
use App\Domain\Property\PropertyService;
use App\Domain\Subscription\SubscriptionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignSubscriptionRequest;
use App\Http\Requests\Admin\BulkPropertyStatusRequest;
use App\Http\Requests\Admin\ChangeOwnerRequest;
use App\Http\Requests\Admin\ChangePropertyStatusRequest;
use App\Http\Requests\Admin\StorePropertyRequest;
use App\Http\Requests\Admin\UpdatePropertyRequest;
use App\Http\Requests\Property\CopyPropertyRequest;
use App\Http\Requests\Property\UploadPropertyMediaRequest;
use App\Infrastructure\Database\Tx;
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
        $plan = $planId ? SubscriptionPlan::query()->find($planId) : null;
        $ownerId = $request->validated('owner_id');
        $property = $ownerId
            ? $this->properties->createWithLanguages($request->propertyFields(), \App\Models\User::query()->where('public_id', $ownerId)->firstOrFail(), $plan, $request->user())
            : $this->properties->register($request->propertyFields(), $request->owner(), $plan, $request->user());

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

    /** Bulk Actions: one status for every selected property, in one transaction. */
    public function bulkStatus(BulkPropertyStatusRequest $request): JsonResponse
    {
        $status = (string) $request->validated('status');
        $codes = (array) $request->validated('codes');

        $changed = Tx::run(fn () => Property::query()->whereIn('code', $codes)->get()
            ->filter(fn (Property $p) => $p->status !== $status && ! in_array($p->status, ['pending_approval', 'rejected'], true))
            ->each(fn (Property $p) => $this->properties->changeStatus($p, $status, $request->validated('reason')))
            ->count());

        return response()->json(['message' => __('property.bulk_done', ['n' => $changed]), 'changed' => $changed]);
    }

    /** Copy Property: settings, rate plans, room types and taxes, never reservations or guests. */
    public function copy(CopyPropertyRequest $request, PropertyCopyService $copier, string $code): JsonResponse
    {
        $copy = $copier->copy($this->find($code), $request->validated(), $request->user());
        $request->session()->flash('success', __('property.copied', ['name' => $copy->name]));

        return response()->json([
            'message' => __('property.copied', ['name' => $copy->name]),
            'code' => $copy->code,
            'redirect' => route('admin.properties', ['selected' => $copy->code]),
        ], 201);
    }

    public function uploadMedia(UploadPropertyMediaRequest $request, PropertyMediaService $media, string $code, string $kind): JsonResponse
    {
        $property = $media->store($this->find($code), $kind, $request->file('image'));

        return response()->json(['message' => __('ui.saved'), 'property' => (new PropertyResource($property->refresh()))->resolve($request)]);
    }

    public function removeMedia(Request $request, PropertyMediaService $media, string $code, string $kind): JsonResponse
    {
        $property = $media->remove($this->find($code), $kind);

        return response()->json(['message' => __('ui.saved'), 'property' => (new PropertyResource($property->refresh()))->resolve($request)]);
    }

    /** Manage owner: hand the property over to another person. */
    public function owner(ChangeOwnerRequest $request, string $code): JsonResponse
    {
        $property = $this->find($code);
        $owner = $this->properties->changeOwner($property, $request->validated());

        return response()->json([
            'message' => __('property.owner_changed', ['name' => $owner->name]),
            'property' => (new PropertyResource($property->refresh()))->resolve($request),
        ]);
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
