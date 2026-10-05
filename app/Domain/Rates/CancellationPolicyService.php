<?php

namespace App\Domain\Rates;

use App\Domain\Audit\AuditLogger;
use App\Infrastructure\Database\Tx;
use App\Models\CancellationPolicy;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

/**
 * Cancellation policies with their rules. Rules are replaced as a whole on update;
 * existing reservations keep the rules frozen in their rate snapshot.
 */
class CancellationPolicyService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  array<string, mixed>  $data  code, name, is_refundable, description, rules[] */
    public function create(array $data): CancellationPolicy
    {
        return Tx::run(function () use ($data) {
            $this->assertRules($data);
            $policy = CancellationPolicy::query()->create([
                'code' => strtoupper((string) $data['code']),
                'name' => $data['name'],
                'is_refundable' => (bool) $data['is_refundable'],
                'description' => $data['description'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);
            $policy->rules()->createMany($this->rules($data['rules'] ?? []));
            $this->audit->log('cancellation_policy.created', $policy, ['after' => $data]);

            return $policy;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(CancellationPolicy $policy, array $data): CancellationPolicy
    {
        return Tx::run(function () use ($policy, $data) {
            $this->assertRules($data + ['is_refundable' => $policy->is_refundable]);
            $policy->fill(array_intersect_key($data, array_flip(['name', 'is_refundable', 'description', 'is_active'])));
            if (isset($data['code'])) {
                $policy->code = strtoupper((string) $data['code']);
            }
            $diff = $this->audit->diff($policy);
            $policy->save();

            if (array_key_exists('rules', $data)) {
                $before = $policy->rules()->get(['applies_to', 'hours_before_arrival', 'charge_type', 'charge_value'])->toArray();
                $policy->rules()->delete();
                $policy->rules()->createMany($this->rules($data['rules']));
                $diff['before']['rules'] = $before;
                $diff['after']['rules'] = $data['rules'];
            }
            $this->audit->log('cancellation_policy.updated', $policy, $diff);

            return $policy;
        });
    }

    /** @param  array<string, mixed>  $data */
    private function assertRules(array $data): void
    {
        $rules = $data['rules'] ?? [];
        foreach ($rules as $i => $rule) {
            $type = $rule['charge_type'];
            $value = $rule['charge_value'] ?? null;
            $value = is_float($value) ? (string) $value : $value;
            if (in_array($type, ['nights', 'percent', 'fixed'], true) && (! Money::isDecimal($value) || ! Money::isPositive((string) $value))) {
                throw ValidationException::withMessages(["rules.$i.charge_value" => __('rates.errors.rule_value_required')]);
            }
            if ($type === 'percent' && Money::compare((string) $value, '100') > 0) {
                throw ValidationException::withMessages(["rules.$i.charge_value" => __('rates.errors.rule_percent_max')]);
            }
        }

        $windows = array_map(fn ($r) => ($r['applies_to'] ?? 'cancellation').':'.(int) $r['hours_before_arrival'], $rules);
        if (count($windows) !== count(array_unique($windows))) {
            throw ValidationException::withMessages(['rules' => __('rates.errors.rule_duplicate_window')]);
        }

        if (! ($data['is_refundable'] ?? true)) {
            foreach ($rules as $i => $rule) {
                if (($rule['applies_to'] ?? 'cancellation') === 'cancellation' && $rule['charge_type'] === 'none') {
                    throw ValidationException::withMessages(["rules.$i.charge_type" => __('rates.errors.non_refundable_free')]);
                }
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     * @return list<array<string, mixed>>
     */
    private function rules(array $rules): array
    {
        return array_map(fn (array $r) => [
            'applies_to' => $r['applies_to'] ?? 'cancellation',
            'hours_before_arrival' => (int) $r['hours_before_arrival'],
            'charge_type' => $r['charge_type'],
            'charge_value' => in_array($r['charge_type'], ['nights', 'percent', 'fixed'], true) ? (string) $r['charge_value'] : null,
        ], $rules);
    }
}
