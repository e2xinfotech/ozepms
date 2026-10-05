<?php

namespace App\Http\Controllers\WebApi\Property;

use App\Domain\Access\AccessService;
use App\Domain\Property\PropertyService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Property\UpdatePropertyRequest;
use App\Http\Resources\PropertyResource;
use App\Models\Property;
use App\Support\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PropertyController extends Controller
{
    /**
     * Details of one of the signed-in user's properties (Properties page side panel).
     * Properties the user does not belong to answer 404.
     */
    public function show(Request $request, AccessService $access, mixed $property, string $code): JsonResponse
    {
        $user = $request->user();
        $target = Property::query()->where('code', $code)->first();

        $isMember = $target && $target->members()->where('user_id', $user->id)->where('status', 'active')->exists();
        $isPlatform = in_array('platform.properties.manage', $access->platformPermissions($user), true);
        if (! $target || (! $isMember && ! $isPlatform)) {
            throw new NotFoundHttpException;
        }

        return response()->json(['property' => (new PropertyResource($target))->resolve($request)]);
    }

    /** Property Configuration of the current property. */
    public function update(UpdatePropertyRequest $request, PropertyContext $context, PropertyService $properties): JsonResponse
    {
        $property = $properties->update($context->property(), $request->propertyFields());

        return response()->json([
            'message' => __('ui.saved'),
            'property' => (new PropertyResource($property->refresh()))->resolve($request),
        ]);
    }
}
