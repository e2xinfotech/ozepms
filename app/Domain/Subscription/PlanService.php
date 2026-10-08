<?php

namespace App\Domain\Subscription;

use App\Domain\Access\AccessService;
use App\Domain\Audit\AuditLogger;
use App\Domain\Platform\ApprovalService;
use App\Infrastructure\Database\Tx;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Subscription plans managed by E2X. Plans are deactivated, never deleted,
 * because subscriptions keep pointing at them.
 */
class PlanService
{
    public const FEATURES = ['booking_engine', 'channel_manager', 'reports', 'ai'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AccessService $access,
        private readonly ApprovalService $approvals,
    ) {}

    /**
     * A Super Admin's plan is usable at once. A plan made by an Admin waits for a Super Admin's approval.
     *
     * @param  array<string, mixed>  $data  validated plan fields
     */
    public function create(array $data, User $by): SubscriptionPlan
    {
        return Tx::run(function () use ($data, $by) {
            $approved = $this->access->allows($by, 'platform.plans.approve');
            $plan = SubscriptionPlan::query()->create($this->normalise($data) + [
                'sort_order' => (int) SubscriptionPlan::query()->max('sort_order') + 1,
                'created_by' => $by->id,
                'approval_status' => $approved ? 'approved' : 'pending',
                'approved_by' => $approved ? $by->id : null,
                'approved_at' => $approved ? now() : null,
            ]);
            $this->audit->log('plan.created', $plan, ['after' => $plan->only(['code', 'name', 'price', 'billing_cycle', 'approval_status'])]);
            if (! $approved) {
                $this->approvals->request('plan', $plan, $by, __('approvals.plan_summary', ['name' => $plan->name, 'code' => $plan->code]));
            }

            return $plan;
        });
    }

    /**
     * Admins may only change plans that are not approved yet (changing a live plan changes prices for
     * paying hotels, so that stays with a Super Admin). Saving a rejected plan sends it back for approval.
     *
     * @param  array<string, mixed>  $data  validated plan fields (code cannot change)
     */
    public function update(SubscriptionPlan $plan, array $data, User $by): SubscriptionPlan
    {
        $canApprove = $this->access->allows($by, 'platform.plans.approve');
        if ($plan->approval_status === 'approved' && ! $canApprove) {
            throw ValidationException::withMessages(['plan' => __('approvals.plan_locked')]);
        }

        return Tx::run(function () use ($plan, $data, $by, $canApprove) {
            unset($data['code']);
            $plan->fill($this->normalise($data));
            $resubmit = $plan->approval_status === 'rejected' && ! $canApprove;
            if ($resubmit) {
                $plan->approval_status = 'pending';
            }
            if ($plan->isDirty()) {
                $diff = $this->audit->diff($plan);
                $plan->save();
                $this->audit->log('plan.updated', $plan, $diff);
            }
            if ($resubmit) {
                $this->approvals->request('plan', $plan, $by, __('approvals.plan_summary', ['name' => $plan->name, 'code' => $plan->code]));
            }

            return $plan;
        });
    }

    /** @param  array<string, mixed>  $data */
    private function normalise(array $data): array
    {
        if (array_key_exists('features', $data)) {
            $given = (array) $data['features'];
            $data['features'] = collect(self::FEATURES)
                ->mapWithKeys(fn (string $f) => [$f => (bool) ($given[$f] ?? false)])
                ->all();
        }

        foreach (['max_room_types', 'max_units', 'max_users'] as $limit) {
            if (array_key_exists($limit, $data) && ($data[$limit] === '' || $data[$limit] === null)) {
                $data[$limit] = null;
            }
        }

        return $data;
    }
}
