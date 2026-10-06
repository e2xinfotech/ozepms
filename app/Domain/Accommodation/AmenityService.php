<?php

namespace App\Domain\Accommodation;

use App\Domain\Accommodation\Reference\AccommodationReference;
use App\Domain\Audit\AuditLogger;
use App\Models\Amenity;
use App\Support\PropertyContext;
use Illuminate\Support\Facades\DB;
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
            'applies_to' => self::appliesTo($data['category']),
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
        if ($amenity->isDirty('category')) {
            $amenity->applies_to = self::appliesTo((string) $amenity->category);
        }
        if ($amenity->isDirty()) {
            $diff = $this->audit->diff($amenity);
            $amenity->save();
            $this->audit->log('amenity.updated', $amenity, $diff, (int) $amenity->property_id);
        }

        return $amenity;
    }

    /**
     * Marks an amenity as a facility of the whole property (pool, parking, spa …), as opposed to
     * something inside a room type. Only amenities that can apply to a property are accepted.
     */
    public function setPropertyFacility(Amenity $amenity, bool $offered): void
    {
        $propertyId = $this->context->id();
        if (! in_array('property', explode(',', (string) $amenity->applies_to), true)) {
            throw ValidationException::withMessages(['offered' => __('amenities.errors.not_a_facility')]);
        }

        $exists = DB::table('property_amenities')->where('property_id', $propertyId)->where('amenity_id', $amenity->id)->exists();
        if ($exists === $offered) {
            return;
        }
        if ($offered) {
            DB::table('property_amenities')->insert(['property_id' => $propertyId, 'amenity_id' => $amenity->id]);
        } else {
            DB::table('property_amenities')->where('property_id', $propertyId)->where('amenity_id', $amenity->id)->delete();
        }
        $this->audit->log($offered ? 'amenity.facility_added' : 'amenity.facility_removed', $amenity, ['after' => ['amenity' => $amenity->code]], $propertyId);
    }

    /** Property-wide categories can be property facilities; the rest belong to rooms. */
    private static function appliesTo(string $category): string
    {
        return in_array($category, ['property', 'service'], true) ? 'property,room_type' : 'room_type,unit';
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
