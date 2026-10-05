<?php

namespace App\Http\Middleware;

use App\Domain\Access\AccessService;
use App\Models\Property;
use App\Models\PropertyUser;
use App\Support\PropertyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Reads {property} (the property code) from the URL, checks that the signed-in user
 * may work in it and binds it to PropertyContext for the rest of the request.
 * Unknown codes and properties the user does not belong to both answer 404,
 * so the response never reveals whether a property exists.
 */
class ResolveProperty
{
    public function __construct(
        private readonly PropertyContext $context,
        private readonly AccessService $access,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $code = $request->route('property');
        $code = $code instanceof Property ? $code->code : (string) $code;
        $user = $request->user();

        $property = Property::query()->where('code', $code)->first();
        if (! $property || ! $user) {
            throw new NotFoundHttpException;
        }

        $membership = PropertyUser::query()->withoutGlobalScope('property')
            ->with('role')
            ->where('property_id', $property->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        $supportMode = false;
        if (! $membership) {
            if (! in_array('platform.properties.manage', $this->access->platformPermissions($user), true)) {
                throw new NotFoundHttpException;
            }
            $supportMode = true;
        }

        if (! $supportMode && in_array($property->status, ['suspended', 'inactive'], true)) {
            abort(403, __('property.suspended'));
        }

        $this->context->set($property, $membership, $supportMode);
        $request->route()->setParameter('property', $property);

        if ($user->last_property_id !== $property->id && ! $supportMode) {
            $user->forceFill(['last_property_id' => $property->id])->saveQuietly();
        }

        return $next($request);
    }
}
