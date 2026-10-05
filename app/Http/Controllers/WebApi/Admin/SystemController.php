<?php

namespace App\Http\Controllers\WebApi\Admin;

use App\Domain\Platform\SystemHealthService;
use App\Http\Controllers\Controller;
use App\Models\SystemErrorEvent;
use Illuminate\Http\JsonResponse;

class SystemController extends Controller
{
    /** Marks an error group as resolved; it reopens automatically if it happens again. */
    public function resolve(SystemHealthService $health, string $event): JsonResponse
    {
        $health->resolve(SystemErrorEvent::query()->where('fingerprint', $event)->firstOrFail());

        return response()->json(['message' => __('admin.resolved')]);
    }
}
