<?php

namespace App\Http\Middleware;

use App\Models\RequestTrail;
use App\Support\PropertyContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Leaves one row for every request that can change data, so any change can be traced to a person,
 * a route, a time and an address even where a service wrote no audit entry. Field names only; values,
 * passwords, tokens and files are never stored. A failure here never breaks the request.
 */
class RecordRequestTrail
{
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** Browser error reports are noise, not changes. */
    private const SKIP = ['web-api/client-errors'];

    private const MAX_FIELDS = 80;

    public function handle(Request $request, Closure $next): Response
    {
        $before = $request->hasSession() ? $request->user()?->id : null;
        $response = $next($request);

        if (in_array($request->method(), self::WRITE_METHODS, true) && ! $request->is(...self::SKIP)) {
            $this->record($request, $response, $before);
        }

        return $response;
    }

    private function record(Request $request, Response $response, ?int $before): void
    {
        try {
            $route = $request->route();
            $session = $request->hasSession();
            $userId = $session ? ($request->user()?->id ?? $before) : null;
            $state = $session ? $request->session()->get('impersonation') : null;
            $impersonator = is_array($state) && (int) ($state['target_id'] ?? 0) === (int) $userId ? (int) $state['actor_id'] : null;
            $context = app(PropertyContext::class);

            RequestTrail::query()->create([
                'request_id' => $request->attributes->get('request_id'),
                'user_id' => $userId,
                'impersonator_id' => $impersonator,
                'property_id' => $context->has() ? $context->id() : null,
                'method' => $request->method(),
                'route_name' => $route?->getName() ? mb_substr($route->getName(), 0, 120) : null,
                'route_uri' => mb_substr($route ? $route->uri() : $request->path(), 0, 190),
                'route_params' => $route ? $this->params($route->parameters()) : null,
                'field_names' => $this->fieldNames($request),
                'status' => $response->getStatusCode(),
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::channel('security')->error('Request trail could not be written', ['error' => $e->getMessage()]);
        }
    }

    /** Route values such as ids; anything that could act as a secret is dropped. */
    private function params(array $parameters): ?array
    {
        $out = [];
        foreach ($parameters as $name => $value) {
            if (preg_match('/token|secret|key|connection/i', (string) $name)) {
                continue;
            }
            $out[$name] = is_scalar($value) ? mb_substr((string) $value, 0, 80) : (is_object($value) && method_exists($value, 'getRouteKey') ? (string) $value->getRouteKey() : null);
        }

        return $out ?: null;
    }

    /** @return array<int, string>|null */
    private function fieldNames(Request $request): ?array
    {
        $names = [];
        $walk = function (array $data, string $prefix, int $depth) use (&$walk, &$names) {
            foreach ($data as $key => $value) {
                if (count($names) >= self::MAX_FIELDS) {
                    return;
                }
                if (is_int($key)) {
                    // List items share one entry: "lines.*.amount".
                    if (is_array($value) && $depth < 3) {
                        $walk($value, $prefix.'*.', $depth + 1);
                    }

                    continue;
                }
                $names[] = mb_substr($prefix.$key, 0, 80);
                if (is_array($value) && $depth < 3) {
                    $walk($value, $prefix.$key.'.', $depth + 1);
                }
            }
        };
        $walk($request->except(['_token']), '', 0);
        foreach (array_keys($request->allFiles()) as $file) {
            $names[] = 'file:'.mb_substr((string) $file, 0, 70);
        }

        return $names ? array_values(array_unique($names)) : null;
    }
}
