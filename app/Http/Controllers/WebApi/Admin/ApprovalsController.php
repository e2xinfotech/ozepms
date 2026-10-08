<?php

namespace App\Http\Controllers\WebApi\Admin;

use App\Domain\Platform\ApprovalService;
use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApprovalsController extends Controller
{
    public function decide(Request $request, ApprovalService $approvals, string $approval): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'string', 'in:approve,reject'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $model = ApprovalRequest::query()->where('public_id', $approval)->firstOrFail();

        $approvals->decide($model, $request->user(), $data['decision'] === 'approve', $data['note'] ?? null);

        return response()->json(['message' => __('approvals.decided_ok'), 'status' => $model->refresh()->status]);
    }
}
