<?php

namespace App\Domain\Billing;

use App\Domain\Audit\AuditLogger;
use App\Infrastructure\Database\Tx;
use App\Models\Property;
use App\Models\Service;
use App\Models\TaxCategory;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

/** The property's extras / services that can be posted to folios (setup page "Services & extras"). */
class ServiceCatalogService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  array<string, mixed>  $data  validated: code, name, description?, price, tax_category, sac_hsn_code?, posting_rule, is_active?, sort_order? */
    public function create(Property $property, array $data): Service
    {
        return Tx::run(function () use ($property, $data) {
            $this->assertCodeFree($property, (string) $data['code']);
            $service = Service::query()->create($this->attributes($data) + ['property_id' => $property->id, 'is_active' => (bool) ($data['is_active'] ?? true)]);
            $this->audit->log('service.created', $service, ['after' => $service->only(['code', 'name', 'price', 'posting_rule'])], $property->id);

            return $service;
        });
    }

    public function update(Service $service, array $data): Service
    {
        return Tx::run(function () use ($service, $data) {
            $service = Service::query()->where('property_id', $service->property_id)->whereKey($service->id)->lockForUpdate()->firstOrFail();
            if (strtoupper((string) $data['code']) !== $service->code) {
                $this->assertCodeFree(Property::query()->findOrFail($service->property_id), (string) $data['code'], $service->id);
            }
            $service->fill($this->attributes($data));
            if (array_key_exists('is_active', $data)) {
                $service->is_active = (bool) $data['is_active'];
            }
            $diff = $this->audit->diff($service);
            $service->save();
            $this->audit->log('service.updated', $service, $diff, $service->property_id);

            return $service;
        });
    }

    public function setActive(Service $service, bool $active): Service
    {
        $service->is_active = $active;
        $diff = $this->audit->diff($service);
        $service->save();
        $this->audit->log($active ? 'service.activated' : 'service.deactivated', $service, $diff, $service->property_id);

        return $service;
    }

    /**
     * Quantity for a stay: once → 1, per_night → nights, per_person → guests, per_person_night → both.
     */
    public static function quantityFor(Service $service, int $nights, int $persons): string
    {
        $nights = max(1, $nights);
        $persons = max(1, $persons);

        return match ($service->posting_rule) {
            'per_night' => (string) $nights,
            'per_person' => (string) $persons,
            'per_person_night' => (string) ($nights * $persons),
            default => '1',
        };
    }

    private function attributes(array $data): array
    {
        $category = TaxCategory::query()->where('code', $data['tax_category'])->first()
            ?? throw ValidationException::withMessages(['tax_category' => __('billing.errors.tax_category')]);

        return [
            'code' => strtoupper(trim((string) $data['code'])),
            'name' => trim((string) $data['name']),
            'description' => isset($data['description']) && trim((string) $data['description']) !== '' ? trim((string) $data['description']) : null,
            'price' => Money::round((string) $data['price'], 2),
            'tax_category_id' => $category->id,
            'sac_hsn_code' => isset($data['sac_hsn_code']) && trim((string) $data['sac_hsn_code']) !== '' ? trim((string) $data['sac_hsn_code']) : $category->default_sac_hsn,
            'posting_rule' => (string) $data['posting_rule'],
            'department' => in_array($data['department'] ?? 'other', \App\Models\Service::DEPARTMENTS, true) ? $data['department'] ?? 'other' : 'other',
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }

    private function assertCodeFree(Property $property, string $code, ?int $exceptId = null): void
    {
        $exists = Service::query()->where('property_id', $property->id)->where('code', strtoupper(trim($code)))
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->exists();
        if ($exists) {
            throw ValidationException::withMessages(['code' => __('billing.errors.code_taken')]);
        }
    }
}
