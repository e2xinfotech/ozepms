<?php

namespace App\Domain\Subscription;

use App\Domain\Audit\AuditLogger;
use App\Infrastructure\Database\Tx;
use App\Models\Property;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Subscription lifecycle. Expiry never deletes data: an expired property becomes read-only.
 */
class SubscriptionService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function startTrial(Property $property, SubscriptionPlan $plan, ?User $by = null): Subscription
    {
        $days = $plan->trial_days ?: (int) config('ozepms.subscription.default_trial_days');
        $start = CarbonImmutable::today();

        return $this->create($property, $plan, $days > 0 ? 'trial' : 'active', $start, $start->addDays(max($days, 1)), $by);
    }

    /**
     * Starts a new paid period and closes the current one.
     *
     * @param  string|null  $price  decimal string; null uses the plan price
     */
    public function assign(Property $property, SubscriptionPlan $plan, CarbonImmutable $startsOn, CarbonImmutable $endsOn, string|float|null $price, ?User $by, ?string $notes = null): Subscription
    {
        return Tx::run(function () use ($property, $plan, $startsOn, $endsOn, $price, $by, $notes) {
            $property->subscriptions()
                ->whereIn('status', ['trial', 'active', 'grace', 'expired', 'suspended'])
                ->update(['status' => 'cancelled', 'updated_at' => now()]);

            return $this->create($property, $plan, 'active', $startsOn, $endsOn, $by, $price === null ? null : (string) $price, $notes);
        });
    }

    /** Plan used when an owner registers a property themselves. */
    public function defaultPlan(): ?SubscriptionPlan
    {
        $code = config('ozepms.subscription.default_plan');

        return SubscriptionPlan::query()->where('is_active', true)->where('code', $code)->first()
            ?? SubscriptionPlan::query()->where('is_active', true)->orderBy('sort_order')->first();
    }

    public function suspend(Subscription $subscription, ?string $reason = null): void
    {
        $subscription->update(['status' => 'suspended', 'notes' => $reason]);
        $this->audit->log('subscription.suspended', $subscription, ['reason' => $reason], $subscription->property_id);
    }

    /**
     * State used by middleware and the UI.
     *
     * @return array{status: string, plan: ?string, ends_on: ?string, days_left: ?int, read_only: bool, warning: bool}
     */
    public function state(Property $property): array
    {
        $sub = $property->currentSubscription;

        if (! $sub) {
            return ['status' => 'none', 'plan' => null, 'ends_on' => null, 'days_left' => null, 'read_only' => true, 'warning' => true];
        }

        $daysLeft = (int) CarbonImmutable::today()->diffInDays($sub->ends_on, false);
        $readOnly = in_array($sub->status, ['expired', 'suspended', 'cancelled'], true);

        return [
            'status' => $sub->status,
            'plan' => $sub->plan?->name,
            'ends_on' => $sub->ends_on->toDateString(),
            'days_left' => $daysLeft,
            'read_only' => $readOnly,
            'warning' => $readOnly || $sub->status === 'grace' || $daysLeft <= (int) config('ozepms.subscription.expiry_warning_days'),
        ];
    }

    /** Daily job: moves subscriptions through active → grace → expired. */
    public function refreshStatuses(): int
    {
        $today = CarbonImmutable::today();
        $changed = 0;

        Subscription::query()
            ->whereIn('status', ['trial', 'active'])
            ->whereDate('ends_on', '<', $today)
            ->with('plan')
            ->each(function (Subscription $sub) use ($today, &$changed) {
                $grace = $sub->plan?->grace_days ?? (int) config('ozepms.subscription.default_grace_days');
                $graceEnds = CarbonImmutable::parse($sub->ends_on)->addDays($grace);
                $status = $grace > 0 && $graceEnds->gte($today) ? 'grace' : 'expired';
                $sub->update(['status' => $status, 'grace_ends_on' => $grace > 0 ? $graceEnds : null]);
                $this->audit->log('subscription.'.$status, $sub, [], $sub->property_id);
                $changed++;
            });

        Subscription::query()
            ->where('status', 'grace')
            ->whereDate('grace_ends_on', '<', $today)
            ->each(function (Subscription $sub) use (&$changed) {
                $sub->update(['status' => 'expired']);
                $this->audit->log('subscription.expired', $sub, [], $sub->property_id);
                $changed++;
            });

        return $changed;
    }

    private function create(Property $property, SubscriptionPlan $plan, string $status, CarbonImmutable $start, CarbonImmutable $end, ?User $by, ?string $price = null, ?string $notes = null): Subscription
    {
        $subscription = Subscription::query()->create([
            'property_id' => $property->id,
            'plan_id' => $plan->id,
            'status' => $status,
            'starts_on' => $start,
            'ends_on' => $end,
            'price' => $price ?? $plan->price,
            'currency_code' => $plan->currency_code,
            'auto_renew' => true,
            'notes' => $notes,
            'created_by' => $by?->id,
        ]);

        $this->audit->log('subscription.'.$status, $subscription, ['after' => [
            'plan' => $plan->code, 'starts_on' => $start->toDateString(), 'ends_on' => $end->toDateString(),
        ]], $property->id);

        $property->unsetRelation('currentSubscription');

        return $subscription;
    }
}
