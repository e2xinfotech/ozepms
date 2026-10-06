<?php

namespace App\Http\Middleware;

use App\Domain\Api\ApiKeyService;
use App\Domain\Subscription\SubscriptionService;
use App\Models\Property;
use App\Support\PropertyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * /api/v1: "Authorization: Bearer ozk_…" (or X-Api-Key) → the key's property becomes the current
 * property. The property must be active, with a subscription that is not read-only and a plan that
 * includes the booking engine. Usage: ->middleware('api.key:availability').
 */
class AuthenticateApiKey
{
    public function __construct(
        private readonly ApiKeyService $keys,
        private readonly SubscriptionService $subscriptions,
        private readonly PropertyContext $context,
    ) {}

    public function handle(Request $request, Closure $next, ?string $ability = null): Response
    {
        $plain = $request->bearerToken() ?: (string) $request->header('X-Api-Key', '');
        $key = $plain !== '' ? $this->keys->verify($plain) : null;
        if ($key === null) {
            return $this->fail(401, 'UNAUTHENTICATED', __('property.api_keys.errors.invalid'));
        }
        if ($ability !== null && ! $key->can($ability)) {
            return $this->fail(403, 'FORBIDDEN', __('property.api_keys.errors.ability', ['ability' => $ability]));
        }
        $property = Property::query()->find($key->property_id);
        $sub = $property?->currentSubscription;
        if ($property === null || $property->status !== 'active' || $sub === null || $this->subscriptions->state($property)['read_only']
            || ! (bool) (($sub->plan?->features ?? [])['booking_engine'] ?? false)) {
            return $this->fail(403, 'PROPERTY_UNAVAILABLE', __('property.api_keys.errors.property'));
        }
        // Remember use at most once a minute (no write on every request).
        if ($key->last_used_at === null || $key->last_used_at->lt(now()->subMinute())) {
            \App\Models\ApiKey::acrossProperties()->whereKey($key->id)->update(['last_used_at' => now(), 'last_used_ip' => $request->ip()]);
        }
        $locale = substr((string) $request->getPreferredLanguage(array_keys(config('ozepms.locales.available'))), 0, 2);
        app()->setLocale($locale ?: (string) $property->default_language ?: config('ozepms.locales.default'));
        $this->context->set($property, null);
        $request->attributes->set('api_key', $key);

        return $next($request);
    }

    private function fail(int $status, string $code, string $message): Response
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
