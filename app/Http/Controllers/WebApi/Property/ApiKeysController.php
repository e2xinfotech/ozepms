<?php

namespace App\Http\Controllers\WebApi\Property;

use App\Domain\Api\ApiKeyService;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Support\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Settings → API keys of the current property (list, create — the key is shown once —, revoke). */
class ApiKeysController extends Controller
{
    public function __construct(private readonly ApiKeyService $keys) {}

    public function index(): JsonResponse
    {
        return response()->json(['keys' => ApiKey::query()->whereNull('revoked_at')->orderByDesc('id')->get()->map(fn (ApiKey $k) => $this->row($k))->all()]);
    }

    public function store(Request $request, PropertyContext $context): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'abilities' => ['required', 'array', 'min:1', 'max:10'], 'abilities.*' => [Rule::in(ApiKey::ABILITIES)],
        ]);
        [$key, $plain] = $this->keys->create($context->property(), $data['name'], $data['abilities'], $request->user());

        return response()->json(['message' => __('property.api_keys.created'), 'key' => $this->row($key), 'plain' => $plain], 201);
    }

    public function destroy(Request $request, mixed $property, string $key): JsonResponse
    {
        $this->keys->revoke(ApiKey::query()->where('public_id', $key)->firstOrFail(), $request->user());

        return response()->json(['message' => __('property.api_keys.revoked')]);
    }

    private function row(ApiKey $k): array
    {
        return ['id' => $k->public_id, 'name' => $k->name, 'prefix' => 'ozk_'.$k->prefix.'_…', 'abilities' => $k->abilities,
            'last_used_at' => $k->last_used_at?->toIso8601String(), 'created_at' => $k->created_at?->toIso8601String()];
    }
}
