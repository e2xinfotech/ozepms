<?php

namespace App\Domain\Property;

use App\Domain\Audit\AuditLogger;
use App\Infrastructure\Database\Tx;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Guest age groups of a property: infants (0 up to X years) and children (X+1 up to Y years).
 * Older guests count as adults. Pricing rules, the booking engine, reservations and the API all read
 * the ranges from here, so one setting decides who is an infant, a child or an adult.
 */
class AgeBandService
{
    public const DEFAULT_INFANT_MAX = 2;

    public const DEFAULT_CHILD_MAX = 12;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array{infant: array{min: int, max: int}, child: array{min: int, max: int}} */
    public function bands(int $propertyId): array
    {
        $rows = DB::table('property_age_bands')->where('property_id', $propertyId)->whereIn('code', ['infant', 'child'])->get()->keyBy('code');
        $infantMax = (int) ($rows['infant']->max_age ?? self::DEFAULT_INFANT_MAX);

        return [
            'infant' => ['min' => 0, 'max' => $infantMax],
            'child' => ['min' => (int) ($rows['child']->min_age ?? $infantMax + 1), 'max' => (int) ($rows['child']->max_age ?? self::DEFAULT_CHILD_MAX)],
        ];
    }

    /** Ages used when a booking gives only head counts: any age inside the band prices the same. */
    public function representativeAges(int $propertyId): array
    {
        $b = $this->bands($propertyId);

        return ['infant' => $b['infant']['min'], 'child' => $b['child']['min']];
    }

    public function save(Property $property, int $infantMax, int $childMax, ?User $by = null): array
    {
        if ($infantMax < 0 || $infantMax > 5) {
            throw ValidationException::withMessages(['infant_max' => __('property.age_bands.errors.infant_range')]);
        }
        if ($childMax <= $infantMax || $childMax > 17) {
            throw ValidationException::withMessages(['child_max' => __('property.age_bands.errors.child_range', ['min' => $infantMax + 1])]);
        }
        $teen = DB::table('property_age_bands')->where('property_id', $property->id)->where('code', 'teen')->first();
        if ($teen !== null && (int) $teen->min_age <= $childMax) {
            throw ValidationException::withMessages(['child_max' => __('property.age_bands.errors.teen_overlap', ['min' => $teen->min_age])]);
        }
        $before = $this->bands($property->id);

        Tx::run(function () use ($property, $infantMax, $childMax) {
            DB::table('property_age_bands')->updateOrInsert(['property_id' => $property->id, 'code' => 'infant'], ['min_age' => 0, 'max_age' => $infantMax]);
            DB::table('property_age_bands')->updateOrInsert(['property_id' => $property->id, 'code' => 'child'], ['min_age' => $infantMax + 1, 'max_age' => $childMax]);
            // Prices and cached searches depend on the age groups.
            DB::table('properties')->where('id', $property->id)->increment('ari_version');
        });
        $after = $this->bands($property->id);
        $this->audit->log('property.age_bands_changed', $property, ['before' => $before, 'after' => $after], $property->id, $by?->id);

        return $after;
    }
}
