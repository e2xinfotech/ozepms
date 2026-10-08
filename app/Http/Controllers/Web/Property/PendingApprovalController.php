<?php

namespace App\Http\Controllers\Web\Property;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Support\Page;
use App\Support\PropertyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/** What an owner sees while a self-registered property waits for approval (or after it was rejected). */
class PendingApprovalController extends Controller
{
    public function __invoke(PropertyContext $context): View|RedirectResponse
    {
        $property = $context->property();
        if (! in_array($property->status, ['pending_approval', 'rejected'], true)) {
            return redirect()->route('property.dashboard', $property->code);
        }

        $request = ApprovalRequest::query()->where('type', 'property_registration')->where('subject_id', $property->id)->latest('id')->first();

        return Page::render('property/pending-approval', [
            'name' => $property->name,
            'code' => $property->code,
            'rejected' => $property->status === 'rejected',
            'note' => $request?->status === 'rejected' ? $request->decision_note : null,
            'requested_at' => $request?->requested_at?->toIso8601String(),
        ], __('approvals.pending_title'));
    }
}
