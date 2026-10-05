<?php

namespace App\Domain\Tax;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tax\Reference\TaxReference;
use App\Infrastructure\Database\Tx;
use App\Models\TaxRule;
use App\Models\TaxRuleScope;
use App\Support\Money;
use App\Support\PropertyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The property's taxes, service charges and fees (Taxes & Fees screen).
 * Rules used on posted charges are deactivated instead of deleted.
 */
class TaxRuleService
{
    private const FIELDS = [
        'code', 'name', 'description', 'kind', 'tax_type', 'calc_type', 'rate', 'slab_basis', 'slab_min', 'slab_max',
        'component_mode', 'is_inclusive', 'is_compound', 'priority', 'effective_from', 'effective_to',
        'is_default_for_new_room_types', 'include_in_displayed_rate', 'is_active',
    ];

    public function __construct(
        private readonly PropertyContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  FIELDS + apply_to (list), room_type_ids (list<int>), rate_plan_ids (list<int>)
     */
    public function create(array $data): TaxRule
    {
        return Tx::run(function () use ($data) {
            $property = $this->context->property();
            $data = $this->normalise($data);
            $this->assertCodeFree($data['code']);

            $rule = new TaxRule(array_intersect_key($data, array_flip(self::FIELDS)));
            $rule->property_id = $property->id;
            $rule->country_iso2 = (string) $property->country_iso2;
            $rule->apply_to = implode(',', $data['apply_to']);
            $rule->tax_category_id = $this->categoryId($data['apply_to']);
            $rule->effective_from ??= now($property->timezone ?: config('app.timezone'))->toDateString();
            $rule->is_active ??= true;
            $rule->save();

            $this->syncScopes($rule, $data['room_type_ids'] ?? [], $data['rate_plan_ids'] ?? []);
            $this->audit->log('tax_rule.created', $rule, ['after' => $rule->only(self::FIELDS) + ['apply_to' => $rule->apply_to]], $property->id);

            return $rule;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(TaxRule $rule, array $data): TaxRule
    {
        $this->assertOwn($rule);

        return Tx::run(function () use ($rule, $data) {
            $merged = $this->normalise(array_merge($rule->only(self::FIELDS), ['apply_to' => $rule->applyToList()], $data));
            if (isset($data['code']) && strtoupper((string) $data['code']) !== $rule->code) {
                $this->assertCodeFree($merged['code'], $rule->id);
            }

            $rule->fill(array_intersect_key($merged, array_flip(self::FIELDS)));
            $rule->apply_to = implode(',', $merged['apply_to']);
            $rule->tax_category_id = $this->categoryId($merged['apply_to']);
            if ($rule->isDirty()) {
                $diff = $this->audit->diff($rule);
                $rule->save();
                $this->audit->log('tax_rule.updated', $rule, $diff, (int) $rule->property_id);
            }

            if (array_key_exists('room_type_ids', $data) || array_key_exists('rate_plan_ids', $data)) {
                $current = $rule->scopes()->get();
                $this->syncScopes(
                    $rule,
                    $data['room_type_ids'] ?? $current->pluck('room_type_id')->filter()->unique()->values()->all(),
                    $data['rate_plan_ids'] ?? $current->pluck('rate_plan_id')->filter()->unique()->values()->all(),
                );
            }

            return $rule;
        });
    }

    public function setActive(TaxRule $rule, bool $active): TaxRule
    {
        return $this->update($rule, ['is_active' => $active]);
    }

    public function setDefaultForNewRoomTypes(TaxRule $rule, bool $default): TaxRule
    {
        return $this->update($rule, ['is_default_for_new_room_types' => $default]);
    }

    /** Deletes a rule that was never used on a charge; used rules must be deactivated. */
    public function delete(TaxRule $rule): void
    {
        $this->assertOwn($rule);
        if (DB::table('folio_line_taxes')->where('tax_rule_id', $rule->id)->exists()) {
            throw ValidationException::withMessages(['rule' => __('taxes.errors.in_use')]);
        }

        Tx::run(function () use ($rule) {
            $before = $rule->only(self::FIELDS);
            $rule->scopes()->delete();
            $rule->delete();
            $this->audit->log('tax_rule.deleted', $rule, ['before' => $before], (int) $rule->property_id);
        });
    }

    /** Copies the country's tax templates (e.g. India GST slabs) into the property's own rules. */
    public function copyTemplates(): int
    {
        $property = $this->context->property();
        $copied = TaxReference::copyTemplatesToProperty($property->id, (string) $property->country_iso2);
        if ($copied > 0) {
            $this->audit->log('tax_rule.defaults_copied', $property, ['after' => ['country' => $property->country_iso2, 'rules' => $copied]], $property->id);
        }

        return $copied;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalise(array $data): array
    {
        $data['code'] = strtoupper(trim((string) ($data['code'] ?? '')));
        foreach (['effective_from', 'effective_to'] as $key) {
            if (($data[$key] ?? null) instanceof \DateTimeInterface) {
                $data[$key] = $data[$key]->format('Y-m-d');
            }
        }
        foreach (['slab_min', 'slab_max'] as $key) {
            if (($data[$key] ?? null) === '') {
                $data[$key] = null;
            }
        }
        $data['apply_to'] = array_values(array_unique(array_filter((array) ($data['apply_to'] ?? []))));
        if ($data['apply_to'] === []) {
            throw ValidationException::withMessages(['apply_to' => __('taxes.errors.apply_to_required')]);
        }

        $kind = $data['kind'] ?? 'tax';
        $data['tax_type'] = $data['tax_type'] ?? ($kind === 'service_charge' ? 'service_charge' : 'other');
        $data['component_mode'] = $data['component_mode'] ?? 'single';
        $data['slab_basis'] = ($data['slab_min'] ?? null) !== null || ($data['slab_max'] ?? null) !== null ? 'unit_night_tariff' : 'none';

        $errors = [];
        $rate = (string) ($data['rate'] ?? '');
        if (! Money::isDecimal($rate) || Money::isNegative($rate)) {
            $errors['rate'] = __('taxes.errors.rate_invalid');
        } elseif (($data['calc_type'] ?? 'percent') === 'percent' && Money::compare($rate, '100') > 0) {
            $errors['rate'] = __('taxes.errors.percent_max');
        }
        if ($data['component_mode'] === 'gst_split' && $kind !== 'tax') {
            $errors['component_mode'] = __('taxes.errors.gst_split_tax_only');
        }
        $min = $data['slab_min'] ?? null;
        $max = $data['slab_max'] ?? null;
        if ($min !== null && $max !== null && Money::compare((string) $min, (string) $max) > 0) {
            $errors['slab_max'] = __('taxes.errors.slab_order');
        }
        $from = $data['effective_from'] ?? null;
        $to = $data['effective_to'] ?? null;
        if ($from !== null && $to !== null && (string) $to < (string) $from) {
            $errors['effective_to'] = __('taxes.errors.dates_order');
        }
        if (! empty($data['is_inclusive']) && ($data['calc_type'] ?? 'percent') !== 'percent') {
            $errors['is_inclusive'] = __('taxes.errors.inclusive_percent_only');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $data['rate'] = Money::round($rate, 4);

        return $data;
    }

    private function assertCodeFree(string $code, ?int $ignoreId = null): void
    {
        $taken = TaxRule::query()->forProperty()->where('code', $code)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['code' => __('taxes.errors.code_taken', ['code' => $code])]);
        }
    }

    /** Country templates and other properties' rules are never changed from a property. */
    private function assertOwn(TaxRule $rule): void
    {
        if ((int) $rule->property_id !== $this->context->id()) {
            abort(404);
        }
    }

    /** @param  list<string>  $applyTo */
    private function categoryId(array $applyTo): int
    {
        return TaxReference::category(TaxReference::APPLY_TO_CATEGORY[$applyTo[0]] ?? 'other')->id;
    }

    /**
     * Room types only → one row each; rate plans only → one row each; both → every pair
     * (the rule then applies to those room types when sold on those rate plans).
     *
     * @param  list<int>  $roomTypeIds
     * @param  list<int>  $ratePlanIds
     */
    private function syncScopes(TaxRule $rule, array $roomTypeIds, array $ratePlanIds): void
    {
        $rows = [];
        if ($roomTypeIds !== [] && $ratePlanIds !== []) {
            foreach ($roomTypeIds as $rt) {
                foreach ($ratePlanIds as $rp) {
                    $rows[] = ['room_type_id' => $rt, 'rate_plan_id' => $rp];
                }
            }
        } else {
            foreach ($roomTypeIds as $rt) {
                $rows[] = ['room_type_id' => $rt, 'rate_plan_id' => null];
            }
            foreach ($ratePlanIds as $rp) {
                $rows[] = ['room_type_id' => null, 'rate_plan_id' => $rp];
            }
        }

        $rule->scopes()->delete();
        foreach ($rows as $row) {
            TaxRuleScope::query()->create($row + ['tax_rule_id' => $rule->id]);
        }
    }
}
