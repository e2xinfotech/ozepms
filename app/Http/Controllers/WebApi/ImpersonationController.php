<?php

namespace App\Http\Controllers\WebApi;

use App\Domain\Platform\ImpersonationService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Start and end "log in as". */
class ImpersonationController extends Controller
{
    public function __construct(private readonly ImpersonationService $impersonation) {}

    public function start(Request $request, string $user): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:'.config('ozepms.security.impersonation_reason_min'), 'max:300']]);
        $target = User::query()->where('public_id', $user)->firstOrFail();

        $this->impersonation->start($request, $target, $data['reason']);

        return response()->json(['message' => __('impersonation.started', ['name' => $target->name]), 'redirect' => route('home')]);
    }

    public function stop(Request $request): JsonResponse
    {
        $actor = $this->impersonation->stop($request);

        return response()->json(['redirect' => $actor ? route('admin.dashboard') : route('login')]);
    }
}
