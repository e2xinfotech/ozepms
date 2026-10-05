<?php

namespace App\Domain\Rates;

use App\Domain\Accommodation\Events\ProductChanged;
use App\Domain\Audit\AuditLogger;
use App\Infrastructure\Database\Tx;
use App\Models\CancellationPolicy;
use App\Models\MealPlan;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\RoomType;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Rate plans (commercial terms) and their room type links. A rate plan is independent of
 * room types; linking it to a room type creates a product priced manually or derived from
 * another product of the same room type.
 */
class RatePlanService
{
    private const FIELDS = [
        'code', 'name', 'description', 'payment_type', 'deposit_value', 'default_min_los', 'default_max_los',
        'min_advance_days', 'max_advance_days', 'sell_on_pms', 'sell_on_booking_engine', 'sell_on_channels', 'sort_order',
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ProductService $products,
    ) {}

    /**
     * @param  array<string, mixed>  $data  FIELDS + meal_plan (MealPlan), cancellation_policy (CancellationPolicy),
     *                                      is_default, is_active, room_types (see syncRoomTypes())
     */
    public function create(array $data): RatePlan
    {
        return Tx::run(function () use ($data) {
            $this->assertStayRules($data);

            $plan = new RatePlan(array_intersect_key($data, array_flip(self::FIELDS)));
            $plan->code = strtoupper((string) $plan->code);
            $plan->meal_plan_id = $this->mealPlan($data)->id;
            $plan->cancellation_policy_id = $this->policy($data)->id;
            $plan->is_active = (bool) ($data['is_active'] ?? true);
            $plan->is_default = false;
            $plan->sort_order ??= (int) RatePlan::query()->max('sort_order') + 1;
            $plan->save();
            $this->audit->log('rate_plan.created', $plan, ['after' => $plan->only(self::FIELDS)]);

            if (! empty($data['is_default'])) {
                $this->makeDefault($plan);
            }
            if (array_key_exists('room_types', $data)) {
                $this->syncRoomTypes($plan, $data['room_types']);
            }

            return $plan;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(RatePlan $plan, array $data): RatePlan
    {
        return Tx::run(function () use ($plan, $data) {
            $this->assertStayRules(array_merge($plan->only(self::FIELDS), $data));

            $plan->fill(array_intersect_key($data, array_flip(self::FIELDS)));
            $plan->code = strtoupper((string) $plan->code);
            if (isset($data['meal_plan'])) {
                $plan->meal_plan_id = $this->mealPlan($data)->id;
            }
            if (isset($data['cancellation_policy'])) {
                $plan->cancellation_policy_id = $this->policy($data)->id;
            }
            if (array_key_exists('is_active', $data) && (bool) $data['is_active'] !== $plan->is_active) {
                $this->assertCanDeactivate($plan, (bool) $data['is_active']);
                $plan->is_active = (bool) $data['is_active'];
            }

            $termsChanged = $plan->isDirty(['meal_plan_id', 'cancellation_policy_id', 'is_active', 'default_min_los', 'default_max_los', 'min_advance_days', 'max_advance_days']);
            if ($plan->isDirty()) {
                $diff = $this->audit->diff($plan);
                $plan->save();
                $this->audit->log('rate_plan.updated', $plan, $diff);
            }

            if (! empty($data['is_default'])) {
                $this->makeDefault($plan);
            }
            if (array_key_exists('room_types', $data)) {
                $this->syncRoomTypes($plan, $data['room_types']);
            }
            if ($termsChanged) {
                $this->dispatchForProducts($plan);
            }

            return $plan;
        });
    }

    public function setActive(RatePlan $plan, bool $active): RatePlan
    {
        if ($plan->is_active === $active) {
            return $plan;
        }

        return Tx::run(function () use ($plan, $active) {
            $this->assertCanDeactivate($plan, $active);
            $plan->is_active = $active;
            $diff = $this->audit->diff($plan);
            $plan->save();
            $this->audit->log($active ? 'rate_plan.activated' : 'rate_plan.deactivated', $plan, $diff);
            $this->dispatchForProducts($plan);

            return $plan;
        });
    }

    /** Copies the plan with its room type links. The copy starts inactive so it can be reviewed. */
    public function copy(RatePlan $plan): RatePlan
    {
        return Tx::run(function () use ($plan) {
            $copy = $plan->replicate(['public_id', 'is_default', 'created_at', 'updated_at', 'deleted_at']);
            $copy->code = $this->copyCode($plan->code);
            $copy->name = Str::limit($plan->name.' '.__('rates.copy_suffix'), 120, '');
            $copy->is_default = false;
            $copy->is_active = false;
            $copy->sort_order = (int) RatePlan::query()->max('sort_order') + 1;
            $copy->save();
            $this->audit->log('rate_plan.copied', $copy, ['after' => ['from' => $plan->code, 'code' => $copy->code]]);

            $products = Product::query()->where('rate_plan_id', $plan->id)->with('occupancyRules')->orderBy('id')->get();
            foreach ($products as $product) {
                $new = $product->replicate(['public_id', 'is_default', 'created_at', 'updated_at']);
                $new->rate_plan_id = $copy->id;
                $new->is_default = false;
                $new->save();
                $new->occupancyRules()->createMany($product->occupancyRules
                    ->map(fn ($r) => $r->only(['guest_type', 'guest_count', 'age_band_id', 'adjust_type', 'adjust_value']))->all());
                ProductChanged::dispatch((int) $new->property_id, $new->id);
            }

            return $copy;
        });
    }

    /**
     * Applies the "Room Types" block of the rate plan form.
     *
     * @param  list<array<string, mixed>>  $entries  room_type (RoomType), enabled, pricing_mode, default_price,
     *                                               parent_rate_plan (?RatePlan), adjust_type, adjust_value, occupancy_rules?
     */
    public function syncRoomTypes(RatePlan $plan, array $entries): void
    {
        Tx::run(function () use ($plan, $entries) {
            foreach ($entries as $index => $entry) {
                /** @var RoomType $roomType */
                $roomType = $entry['room_type'];
                $field = "room_types.$index";
                $existing = Product::query()->where('room_type_id', $roomType->id)->where('rate_plan_id', $plan->id)->first();

                if (empty($entry['enabled'])) {
                    if ($existing && $existing->is_active) {
                        $this->products->setActive($existing, false);
                    }

                    continue;
                }

                $data = ['is_active' => true, 'pricing_mode' => $entry['pricing_mode'] ?? 'manual'];
                if ($data['pricing_mode'] === 'derived') {
                    $parentPlan = $entry['parent_rate_plan'] ?? null;
                    if (! $parentPlan instanceof RatePlan) {
                        throw ValidationException::withMessages(["$field.parent_rate_plan_id" => __('rates.errors.parent_missing')]);
                    }
                    if ($parentPlan->id === $plan->id) {
                        throw ValidationException::withMessages(["$field.parent_rate_plan_id" => __('rates.errors.parent_self')]);
                    }
                    $data['parent'] = Product::query()->where('room_type_id', $roomType->id)->where('rate_plan_id', $parentPlan->id)
                        ->where('is_active', true)->first()
                        ?? throw ValidationException::withMessages(["$field.parent_rate_plan_id" => __('rates.errors.parent_not_linked', [
                            'plan' => $parentPlan->name, 'room_type' => $roomType->name,
                        ])]);
                    $data['adjust_type'] = $entry['adjust_type'] ?? null;
                    $data['adjust_value'] = $entry['adjust_value'] ?? null;
                } else {
                    $data['default_price'] = $entry['default_price'] ?? null;
                }
                if (array_key_exists('occupancy_rules', $entry)) {
                    $data['occupancy_rules'] = $entry['occupancy_rules'] ?? [];
                }

                $product = $this->products->upsert($roomType, $plan, $data, $field);

                // A room type without a default product gets this one, so lists always show a rate plan.
                if (! Product::query()->where('room_type_id', $roomType->id)->where('is_default', true)->where('is_active', true)->exists()) {
                    Product::query()->whereKey($product->id)->update(['is_default' => true]);
                }
            }
        });
    }

    /** @param  array<string, mixed>  $data */
    private function assertStayRules(array $data): void
    {
        $errors = [];
        $min = (int) ($data['default_min_los'] ?? 1);
        $max = $data['default_max_los'] ?? null;
        if ($max !== null && $max !== '' && (int) $max < $min) {
            $errors['default_max_los'] = __('rates.errors.max_los_below_min');
        }
        $from = $data['min_advance_days'] ?? null;
        $to = $data['max_advance_days'] ?? null;
        if ($from !== null && $from !== '' && $to !== null && $to !== '' && (int) $to < (int) $from) {
            $errors['max_advance_days'] = __('rates.errors.window_order');
        }
        $type = $data['payment_type'] ?? 'pay_at_property';
        if (in_array($type, ['deposit_percent', 'deposit_nights'], true)) {
            $value = $data['deposit_value'] ?? null;
            if ($value === null || $value === '' || (float) $value <= 0 || ($type === 'deposit_percent' && (float) $value > 100)) {
                $errors['deposit_value'] = __('rates.errors.deposit_value');
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** Products of other plans that follow this plan's products keep it active. */
    private function assertCanDeactivate(RatePlan $plan, bool $active): void
    {
        if ($active) {
            return;
        }
        if ($plan->is_default) {
            throw ValidationException::withMessages(['is_active' => __('rates.errors.default_cannot_deactivate')]);
        }
        $children = Product::query()
            ->whereIn('parent_product_id', Product::query()->where('rate_plan_id', $plan->id)->select('id'))
            ->where('rate_plan_id', '!=', $plan->id)
            ->where('is_active', true)
            ->count();
        if ($children > 0) {
            throw ValidationException::withMessages(['is_active' => __('rates.errors.has_active_children', ['count' => $children])]);
        }
    }

    private function makeDefault(RatePlan $plan): void
    {
        if (! $plan->is_active) {
            throw ValidationException::withMessages(['is_default' => __('rates.errors.default_inactive')]);
        }
        RatePlan::query()->where('id', '!=', $plan->id)->where('is_default', true)->update(['is_default' => false]);
        if (! $plan->is_default) {
            $plan->is_default = true;
            $plan->save();
            $this->audit->log('rate_plan.made_default', $plan);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function mealPlan(array $data): MealPlan
    {
        $mealPlan = $data['meal_plan'] ?? null;
        if (! $mealPlan instanceof MealPlan) {
            throw ValidationException::withMessages(['meal_plan' => __('rates.errors.meal_plan_required')]);
        }

        return $mealPlan;
    }

    /** @param  array<string, mixed>  $data */
    private function policy(array $data): CancellationPolicy
    {
        $policy = $data['cancellation_policy'] ?? null;
        if (! $policy instanceof CancellationPolicy) {
            throw ValidationException::withMessages(['cancellation_policy' => __('rates.errors.policy_required')]);
        }

        return $policy;
    }

    private function copyCode(string $code): string
    {
        $base = Str::limit(strtoupper($code), 15, '').'-C';
        $candidate = $base;
        $i = 2;
        while (RatePlan::query()->withTrashed()->where('code', $candidate)->exists()) {
            $candidate = $base.$i++;
        }

        return $candidate;
    }

    private function dispatchForProducts(RatePlan $plan): void
    {
        Product::query()->where('rate_plan_id', $plan->id)->pluck('id')
            ->each(fn (int $id) => ProductChanged::dispatch((int) $plan->property_id, $id));
    }
}
