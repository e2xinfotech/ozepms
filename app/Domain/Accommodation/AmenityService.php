<?php

namespace App\Domain\Accommodation;

use App\Domain\Accommodation\Reference\AccommodationReference;
use App\Domain\Audit\AuditLogger;
use App\Models\Amenity;
use App\Support\PropertyContext;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Custom amenities of a property. Global amenities are reference data and read-only here. */
class AmenityService
{
    public function __construct(
        private readonly PropertyContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /** @param  array{name: string, category: string, icon?: ?string}  $data */
    public function create(array $data): Amenity
    {
        $propertyId = $this->context->id();
        $name = trim($data['name']);
        $this->assertNameFree($name, $propertyId);

        $amenity = Amenity::query()->create([
            'property_id' => $propertyId,
            'code' => $this->uniqueCode($name, $propertyId),
            'name' => $name,
            'category' => $data['category'],
            'icon' => $data['icon'] ?? null,
            'applies_to' => 'room_type,unit',
            'is_active' => true,
        ]);
        $this->audit->log('amenity.created', $amenity, ['after' => ['name' => $name, 'category' => $data['category']]], $propertyId);

        return $amenity;
    }

    /** @param  array{name?: string, category?: string, icon?: ?string, is_active?: bool}  $data */
    public function update(Amenity $amenity, array $data): Amenity
    {
        $this->assertCustom($amenity);
        if (isset($data['name']) && trim($data['name']) !== $amenity->name) {
            $this->assertNameFree(trim($data['name']), (int) $amenity->property_id, $amenity->id);
        }

        $amenity->fill(array_intersect_key($data, array_flip(['name', 'category', 'icon', 'is_active'])));
        if ($amenity->isDirty()) {
            $diff = $this->audit->diff($amenity);
            $amenity->save();
            $this->audit->log('amenity.updated', $amenity, $diff, (int) $amenity->property_id);
        }

        return $amenity;
    }

    private function assertCustom(Amenity $amenity): void
    {
        if ((int) $amenity->property_id !== $this->context->id()) {
            throw ValidationException::withMessages(['name' => __('amenities.errors.global_read_only')]);
        }
    }

    private function assertNameFree(string $name, int $propertyId, ?int $ignoreId = null): void
    {
        $globalLabels = array_map(fn (string $code) => mb_strtolower(__('amenities.items.'.$code)), array_keys(AccommodationReference::AMENITIES));
        $customTaken = Amenity::query()->where('property_id', $propertyId)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists();

        if ($customTaken || in_array(mb_strtolower($name), $globalLabels, true)) {
            throw ValidationException::withMessages(['name' => __('amenities.errors.duplicate', ['name' => $name])]);
        }
    }

    private function uniqueCode(string $name, int $propertyId): string
    {
        $slug = Str::limit(Str::slug($name, '_'), 30, '');
        $base = 'c_'.($slug !== '' ? $slug : 'amenity');
        $code = $base;
        $i = 2;
        while (Amenity::query()->where(fn ($q) => $q->whereNull('property_id')->orWhere('property_id', $propertyId))->where('code', $code)->exists()) {
            $code = $base.'_'.$i++;
        }

        return $code;
    }
}
