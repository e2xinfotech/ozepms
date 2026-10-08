<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Access\AccessService;
use App\Domain\Platform\ApprovalService;
use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Support\Listing;
use App\Support\Page;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** Requests waiting for a decision, and the ones already decided. */
class ApprovalsController extends Controller
{
    public function index(Request $request, AccessService $access): View
    {
        $filters = Listing::filters($request, ['tab', 'type']);
        $decided = $filters['tab'] === 'decided';
        $user = $request->user();

        $base = ApprovalRequest::query()->when(in_array($filters['type'], ApprovalRequest::TYPES, true), fn ($q) => $q->where('type', $filters['type']));
        $query = (clone $base)->with(['requester:id,name,email', 'decider:id,name', 'property:id,code,name'])
            ->when($decided, fn ($q) => $q->where('status', '!=', 'pending')->orderByDesc('decided_at'), fn ($q) => $q->where('status', 'pending')->orderBy('id'));

        return Page::render('admin/approvals/index', Listing::paginate($query, $request, fn (ApprovalRequest $r) => [
            'id' => $r->public_id,
            'type' => $r->type,
            'summary' => $r->summary,
            'status' => $r->status,
            'property_code' => $r->property?->code,
            'link' => $r->type === 'channel_connection' && $r->property !== null && isset($r->details['connection'])
                ? route('property.channels.show', [$r->property->code, $r->details['connection']]) : null,
            'requested_by' => $r->requester?->name,
            'requested_by_email' => $r->requester?->email,
            'requested_at' => $r->requested_at?->toIso8601String(),
            'decided_by' => $r->decider?->name,
            'decided_at' => $r->decided_at?->toIso8601String(),
            'note' => $r->decision_note,
            'can_decide' => $r->status === 'pending' && $access->allows($user, ApprovalService::PERMISSION[$r->type]),
        ]) + [
            'filters' => $filters + ['tab' => $decided ? 'decided' : 'pending'],
            'counts' => [
                'pending' => (clone $base)->where('status', 'pending')->count(),
                'decided' => (clone $base)->where('status', '!=', 'pending')->count(),
            ],
            'types' => ApprovalRequest::TYPES,
        ], __('approvals.title'));
    }
}
