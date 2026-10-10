<?php

namespace App\Domain\Platform;

use App\Domain\Access\AccessService;
use App\Domain\Audit\AuditLogger;
use App\Infrastructure\Database\Tx;
use App\Models\ApprovalRequest;
use App\Models\Property;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Notifications\ApprovalDecidedNotification;
use App\Notifications\ApprovalRequestedNotification;
use App\Notifications\ApprovalSubmittedNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Things that need a decision from platform staff: a self-registered property, a plan made by an Admin,
 * a channel connection. One request row per decision; the matching handler applies the outcome.
 */
class ApprovalService
{
    /** Permission needed to decide each type. */
    public const PERMISSION = [
        'property_registration' => 'platform.approvals.manage',
        'plan' => 'platform.plans.approve',
        'channel_connection' => 'platform.channels.approve',
    ];

    public function __construct(private readonly AuditLogger $audit, private readonly AccessService $access) {}

    /** Opens a request (or returns the one already waiting for the same subject) and tells the deciders by e-mail. */
    public function request(string $type, Model $subject, ?User $by, string $summary, ?int $propertyId = null, array $details = []): ApprovalRequest
    {
        return Tx::run(function () use ($type, $subject, $by, $summary, $propertyId, $details) {
            $existing = ApprovalRequest::query()->where('type', $type)->where('subject_id', $subject->getKey())->where('status', 'pending')->first();
            if ($existing !== null) {
                return $existing;
            }

            $request = ApprovalRequest::query()->create([
                'type' => $type,
                'subject_id' => $subject->getKey(),
                'property_id' => $propertyId,
                'requested_by' => $by?->id,
                'summary' => mb_substr($summary, 0, 255),
                'details' => $details ?: null,
            ]);
            $this->audit->log('approval.requested', $request, ['type' => $type, 'summary' => $summary], $propertyId);

            $approvers = $this->approvers($type);
            if ($approvers->isNotEmpty()) {
                Notification::send($approvers, new ApprovalRequestedNotification($request));
            }
            if ($by !== null && ! $approvers->contains('id', $by->id)) {
                try {
                    $by->notify(new ApprovalSubmittedNotification($request));
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            return $request;
        });
    }

    public function decide(ApprovalRequest $request, User $by, bool $approve, ?string $note = null): ApprovalRequest
    {
        if (! $this->access->allows($by, self::PERMISSION[$request->type])) {
            abort(403);
        }
        if ($request->status !== 'pending') {
            throw ValidationException::withMessages(['request' => __('approvals.already_decided')]);
        }
        $note = $note !== null ? trim($note) : null;
        if (! $approve && ($note === null || mb_strlen($note) < 3)) {
            throw ValidationException::withMessages(['note' => __('approvals.note_required')]);
        }

        Tx::run(function () use ($request, $by, $approve, $note) {
            $this->apply($request, $approve, $by);
            $request->forceFill([
                'status' => $approve ? 'approved' : 'rejected',
                'decided_by' => $by->id,
                'decided_at' => now(),
                'decision_note' => $note !== null && $note !== '' ? mb_substr($note, 0, 500) : null,
            ])->save();
            $this->audit->log($approve ? 'approval.approved' : 'approval.rejected', $request, [
                'type' => $request->type, 'summary' => $request->summary, 'note' => $note,
            ], $request->property_id);
        });

        if ($request->requested_by !== null && ($requester = User::query()->find($request->requested_by)) !== null) {
            $requester->notify(new ApprovalDecidedNotification($request->refresh()));
        }

        return $request->refresh();
    }

    /** The subject changes with the decision. */
    private function apply(ApprovalRequest $request, bool $approve, User $by): void
    {
        match ($request->type) {
            'property_registration' => $this->applyProperty($request, $approve),
            'plan' => $this->applyPlan($request, $approve, $by),
            'channel_connection' => app(\App\Domain\Channels\ConnectionApprovalService::class)->apply($request, $approve, $by),
        };
    }

    private function applyProperty(ApprovalRequest $request, bool $approve): void
    {
        $property = Property::query()->whereKey($request->subject_id)->lockForUpdate()->firstOrFail();
        if ($property->status !== 'pending_approval') {
            return;
        }
        $property->forceFill(['status' => $approve ? 'onboarding' : 'rejected'])->save();
        $this->audit->log('property.status_changed', $property, [
            'before' => ['status' => 'pending_approval'], 'after' => ['status' => $property->status],
        ], $property->id);
    }

    private function applyPlan(ApprovalRequest $request, bool $approve, User $by): void
    {
        $plan = SubscriptionPlan::query()->whereKey($request->subject_id)->lockForUpdate()->firstOrFail();
        $plan->forceFill([
            'approval_status' => $approve ? 'approved' : 'rejected',
            'approved_by' => $approve ? $by->id : null,
            'approved_at' => $approve ? now() : null,
        ])->save();
    }

    /** Active platform users who may decide this type. */
    private function approvers(string $type): \Illuminate\Support\Collection
    {
        $ids = DB::table('platform_user_roles')
            ->join('role_permissions', 'role_permissions.role_id', '=', 'platform_user_roles.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('permissions.key', self::PERMISSION[$type])
            ->distinct()->pluck('platform_user_roles.user_id');

        return User::query()->whereIn('id', $ids)->where('status', 'active')->where('is_platform_user', true)->get();
    }
}
