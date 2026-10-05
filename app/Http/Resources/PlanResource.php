<?php

namespace App\Http\Resources;

use App\Domain\Subscription\PlanService;
use App\Models\SubscriptionPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SubscriptionPlan */
class PlanResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        /** @var SubscriptionPlan $plan */
        $plan = $this->resource;

        return [
            'id' => $plan->id,
            'code' => $plan->code,
            'name' => $plan->name,
            'description' => $plan->description,
            'price' => (string) $plan->price,
            'currency_code' => $plan->currency_code,
            'billing_cycle' => $plan->billing_cycle,
            'trial_days' => (int) $plan->trial_days,
            'grace_days' => (int) $plan->grace_days,
            'max_room_types' => $plan->max_room_types,
            'max_units' => $plan->max_units,
            'max_users' => $plan->max_users,
            'features' => collect(PlanService::FEATURES)->mapWithKeys(fn ($f) => [$f => $plan->hasFeature($f)])->all(),
            'is_active' => $plan->is_active,
            'properties' => (int) ($plan->properties_count ?? 0),
        ];
    }
}
