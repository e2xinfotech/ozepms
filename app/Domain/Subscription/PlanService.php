<?php

namespace App\Domain\Subscription;

use App\Domain\Audit\AuditLogger;
use App\Infrastructure\Database\Tx;
use App\Models\SubscriptionPlan;

/**
 * Subscription plans managed by E2X. Plans are deactivated, never deleted,
 * because subscriptions keep pointing at them.
 */
class PlanService
{
    public const FEATURES = ['booking_engine', 'channel_manager', 'reports', 'ai'];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  array<string, mixed>  $data  validated plan fields */
    public function create(array $data): SubscriptionPlan
    {
        return Tx::run(function () use ($data) {
            $plan = SubscriptionPlan::query()->create($this->normalise($data) + [
                'sort_order' => (int) SubscriptionPlan::query()->max('sort_order') + 1,
            ]);
            $this->audit->log('plan.created', $plan, ['after' => $plan->only(['code', 'name', 'price', 'billing_cycle'])]);

            return $plan;
        });
    }

    /** @param  array<string, mixed>  $data  validated plan fields (code cannot change) */
    public function update(SubscriptionPlan $plan, array $data): SubscriptionPlan
    {
        return Tx::run(function () use ($plan, $data) {
            unset($data['code']);
            $plan->fill($this->normalise($data));
            if ($plan->isDirty()) {
                $diff = $this->audit->diff($plan);
                $plan->save();
                $this->audit->log('plan.updated', $plan, $diff);
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
