<?php

namespace Database\Seeders;

use App\Domain\Accommodation\AmenityService;
use App\Domain\Accommodation\InProperty;
use App\Domain\Accommodation\PhysicalUnitService;
use App\Domain\Accommodation\RoomTypeService;
use App\Domain\Accommodation\UnitBlockService;
use App\Domain\Accommodation\UnitNightGuard;
use App\Domain\Rates\PropertyDefaultsService;
use App\Domain\Rates\RatePlanService;
use App\Domain\Tax\TaxRuleService;
use App\Models\Amenity;
use App\Models\CancellationPolicy;
use App\Models\MealPlan;
use App\Models\PhysicalUnit;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\TaxRule;
use App\Models\UnitBlock;
use Illuminate\Database\Seeder;

/**
 * Demo rooms, rates and taxes for the demo properties (P1001, P1002):
 * three room types with PMS rooms, amenities, three rate plans (Room Only, Breakfast
 * Included, Non-Refundable derived −10 %), their products and the local tax rules.
 * Safe to run repeatedly: anything that already exists (by code) is left alone.
 *   php artisan db:seed --class=AccommodationDemoSeeder
 */
class AccommodationDemoSeeder extends Seeder
{
    private const CODES = ['P1001', 'P1002'];

    private const SLUGS = ['sunrise-grand-hotel', 'ocean-view-resort'];

    private static bool $creatingProperties = false;

    /** Nightly prices per currency: room type code => [room only, breakfast supplement]. */
    private const PRICES = [
        'AED' => ['DLX' => ['550', '60'], 'SUP' => ['450', '60'], 'FAM' => ['900', '120']],
        'INR' => ['DLX' => ['5500', '800'], 'SUP' => ['4200', '800'], 'FAM' => ['9500', '1500']],
    ];

    /** code => [name, category, description, base, max adults, max children, max occupancy, beds, rooms (range), floor, amenities] */
    private const ROOM_TYPES = [
        'DLX' => ['Deluxe King', 'deluxe', 'Spacious room with a king bed, city view and premium amenities.', 2, 3, 1, 3,
            [['bed_type' => 'king', 'quantity' => 1]], '101-110', '1', ['wifi', 'air_conditioning', 'smart_tv', 'minibar', 'safe', 'private_bathroom', 'shower', 'hair_dryer', 'toiletries']],
        'SUP' => ['Superior Twin', 'superior', 'Modern room with two single beds, ideal for friends and colleagues.', 2, 2, 1, 3,
            [['bed_type' => 'twin', 'quantity' => 1]], '201-208', '2', ['wifi', 'air_conditioning', 'tv', 'desk', 'private_bathroom', 'shower', 'kettle']],
        'FAM' => ['Family Suite', 'family', 'Two-room suite with a separate living area and space for the whole family.', 2, 4, 2, 6,
            [['bed_type' => 'king', 'quantity' => 1], ['bed_type' => 'sofa_bed', 'quantity' => 1]], '301-304', '3', ['wifi', 'air_conditioning', 'smart_tv', 'seating_area', 'balcony', 'bathtub', 'refrigerator', 'coffee_maker', 'baby_cot']],
    ];

    public function run(): void
    {
        $this->call(AccommodationReferenceSeeder::class);

        $properties = $this->properties();
        if ($properties->isEmpty()) {
            if (self::$creatingProperties || ! class_exists(DemoSeeder::class)) {
                $this->command?->warn('No demo properties found; nothing to seed.');

                return;
            }
            // The demo properties are created by DemoSeeder (through PropertyService), which calls this seeder again.
            self::$creatingProperties = true;
            try {
                $this->call(DemoSeeder::class);
            } finally {
                self::$creatingProperties = false;
            }

            return;
        }

        foreach ($properties as $property) {
            InProperty::run($property, fn () => $this->seedProperty($property));
            $this->command?->info("Rooms, rates and taxes ready for {$property->code}.");
        }
    }

    /** @return \Illuminate\Support\Collection<int, Property> */
    private function properties()
    {
        return self::demoProperties();
    }

    /**
     * The demo properties, by code or by slug (codes come from a counter and can differ, e.g. in
     * tests). Shared by every module demo seeder.
     *
     * @return \Illuminate\Support\Collection<int, Property>
     */
    public static function demoProperties()
    {
        return Property::query()->whereIn('code', self::CODES)->orWhereIn('slug', self::SLUGS)->orderBy('id')->get();
    }

    private function seedProperty(Property $property): void
    {
        // Policies, BAR and country tax templates (normally done when the property was created).
        app(PropertyDefaultsService::class)->seed($property);

        $this->amenities();
        $plans = $this->ratePlans();
        $prices = self::PRICES[$property->currency_code] ?? self::PRICES['INR'];

        foreach (self::ROOM_TYPES as $code => [$name, $category, $description, $base, $maxAdults, $maxChildren, $maxOccupancy, $beds, $range, $floor, $amenities]) {
            if (RoomType::query()->withTrashed()->where('code', $code)->exists()) {
                continue;
            }
            [$roomOnly, $breakfast] = $prices[$code];
            $occupancy = $this->occupancyRules($roomOnly, $maxAdults, $base, $maxChildren);

            $roomType = app(RoomTypeService::class)->create([
                'code' => $code, 'name' => $name, 'category' => $category, 'description' => $description,
                'base_adults' => $base, 'max_adults' => $maxAdults, 'max_children' => $maxChildren, 'max_infants' => 1,
                'max_occupancy' => $maxOccupancy, 'extra_bed_allowed' => $code !== 'SUP', 'max_extra_beds' => $code === 'SUP' ? 0 : 1,
                'size_value' => ['DLX' => '32', 'SUP' => '28', 'FAM' => '55'][$code], 'size_unit' => 'sqm',
                'smoking_policy' => 'non_smoking', 'view_label' => ['DLX' => 'City view', 'SUP' => 'Garden view', 'FAM' => 'Sea view'][$code],
                'beds' => $beds, 'amenities' => $amenities,
                'products' => [
                    ['rate_plan' => $plans['BAR'], 'enabled' => true, 'pricing_mode' => 'manual', 'default_price' => $roomOnly, 'is_default' => $code !== 'FAM', 'occupancy_rules' => $occupancy],
                    // Families mostly book with breakfast, so that is the suite's default plan.
                    ['rate_plan' => $plans['BB'], 'enabled' => true, 'pricing_mode' => 'manual', 'default_price' => (string) ((int) $roomOnly + (int) $breakfast), 'is_default' => $code === 'FAM', 'occupancy_rules' => $occupancy],
                    ['rate_plan' => $plans['NRF'], 'enabled' => true, 'pricing_mode' => 'derived', 'parent_rate_plan' => $plans['BAR'], 'adjust_type' => 'percent', 'adjust_value' => '-10'],
                ],
            ]);
            app(PhysicalUnitService::class)->bulkCreate($roomType, ['mode' => 'range', 'range' => $range, 'floor' => $floor]);
        }

        $this->roomStatuses($property);
        $this->taxes($property);
    }

    private function amenities(): void
    {
        $service = app(AmenityService::class);
        foreach ([['Rooftop pool access', 'property', 'waves'], ['Welcome drink', 'service', 'wine']] as [$name, $category, $icon]) {
            if (! Amenity::query()->where('property_id', app(\App\Support\PropertyContext::class)->id())->where('name', $name)->exists()) {
                $service->create(['name' => $name, 'category' => $category, 'icon' => $icon]);
            }
        }

        // Facilities of the whole property (shown on the property profile).
        foreach (['parking', 'swimming_pool', 'restaurant', 'fitness_center', 'wheelchair_accessible'] as $code) {
            $amenity = Amenity::query()->whereNull('property_id')->where('code', $code)->first();
            if ($amenity) {
                $service->setPropertyFacility($amenity, true);
            }
        }
    }

    /** @return array<string, RatePlan> */
    private function ratePlans(): array
    {
        $service = app(RatePlanService::class);
        $meal = fn (string $code) => MealPlan::query()->whereNull('property_id')->where('code', $code)->firstOrFail();
        $policy = fn (string $code) => CancellationPolicy::query()->where('code', $code)->firstOrFail();

        $bar = RatePlan::query()->where('code', 'BAR')->firstOrFail();
        // The default plan keeps its generated name unless someone changed it.
        if ($bar->name === __('rates.defaults.bar_name')) {
            $service->update($bar, ['name' => 'Room Only', 'description' => 'Best available rate, room without meals.']);
        }

        $plans = ['BAR' => $bar];
        $definitions = [
            'BB' => ['Breakfast Included', 'Daily breakfast for all guests in the room.', 'BB', 'FLEX', 'pay_at_property', 1, true],
            'NRF' => ['Non-Refundable', 'Our lowest price: 10 % off the Room Only rate, paid at booking and not refundable.', 'RO', 'NRF', 'prepay_full', 1, true],
        ];
        foreach ($definitions as $code => [$name, $description, $mealCode, $policyCode, $payment, $minLos, $channels]) {
            $plans[$code] = RatePlan::query()->where('code', $code)->first() ?? $service->create([
                'code' => $code, 'name' => $name, 'description' => $description,
                'meal_plan' => $meal($mealCode), 'cancellation_policy' => $policy($policyCode),
                'payment_type' => $payment, 'default_min_los' => $minLos, 'max_advance_days' => 365,
                'sell_on_pms' => true, 'sell_on_booking_engine' => true, 'sell_on_channels' => $channels,
            ]);
        }

        return $plans;
    }

    /** Single occupancy −10 %, each extra adult +25 % of the base price, first child +10 %. */
    private function occupancyRules(string $price, int $maxAdults, int $base, int $maxChildren): array
    {
        $rules = [['guest_type' => 'adult', 'guest_count' => 1, 'adjust_type' => 'percent', 'adjust_value' => '-10']];
        if ($maxAdults > $base) {
            $rules[] = ['guest_type' => 'adult', 'guest_count' => $base + 1, 'adjust_type' => 'fixed', 'adjust_value' => (string) round((int) $price * 0.25)];
        }
        if ($maxChildren > 0) {
            $rules[] = ['guest_type' => 'child', 'guest_count' => 1, 'age_band' => 'child', 'adjust_type' => 'fixed', 'adjust_value' => (string) round((int) $price * 0.10)];
        }

        return $rules;
    }

    /** A few rooms in other states so the Rooms screen shows every status. */
    private function roomStatuses(Property $property): void
    {
        $today = app(UnitNightGuard::class)->today($property);
        $units = app(PhysicalUnitService::class);

        foreach (['103', '206'] as $name) {
            $unit = PhysicalUnit::query()->where('name', $name)->first();
            if ($unit && $unit->housekeeping_status === 'clean' && $unit->last_cleaned_at === null) {
                $units->setHousekeeping($unit, 'dirty');
            }
        }

        $blocks = ['205' => ['maintenance', 'Air conditioning repair'], '304' => ['out_of_order', 'Bathroom renovation']];
        foreach ($blocks as $name => [$type, $reason]) {
            $unit = PhysicalUnit::query()->where('name', $name)->first();
            if ($unit && ! UnitBlock::query()->where('unit_id', $unit->id)->exists()) {
                app(UnitBlockService::class)->block($unit, $type, $today, date('Y-m-d', strtotime($today.' +3 days')), $reason);
            }
        }
    }

    private function taxes(Property $property): void
    {
        $service = app(TaxRuleService::class);
        $rules = $property->country_iso2 === 'AE'
            ? [
                ['code' => 'VAT', 'name' => 'VAT', 'kind' => 'tax', 'tax_type' => 'vat', 'calc_type' => 'percent', 'rate' => '5', 'apply_to' => ['room_charges', 'add_ons', 'fnb'], 'priority' => 30, 'description' => 'UAE value added tax.'],
                ['code' => 'MUN', 'name' => 'Municipality Fee', 'kind' => 'tax', 'tax_type' => 'city', 'calc_type' => 'percent', 'rate' => '7', 'apply_to' => ['room_charges'], 'priority' => 20, 'description' => 'Dubai Municipality fee on room charges.'],
                ['code' => 'SC', 'name' => 'Service Charge', 'kind' => 'service_charge', 'calc_type' => 'percent', 'rate' => '10', 'apply_to' => ['room_charges', 'fnb'], 'priority' => 10, 'is_default_for_new_room_types' => true],
                ['code' => 'TDF', 'name' => 'Tourism Dirham Fee', 'kind' => 'tax', 'tax_type' => 'tourism', 'calc_type' => 'fixed_per_night', 'rate' => '15', 'apply_to' => ['room_charges'], 'priority' => 40, 'description' => 'Mandatory tourism fee as per Dubai Tourism regulations.', 'is_default_for_new_room_types' => true],
                ['code' => 'ATF', 'name' => 'Airport Transfer Fee', 'kind' => 'fee', 'calc_type' => 'fixed_per_booking', 'rate' => '50', 'apply_to' => ['add_ons'], 'priority' => 50, 'is_active' => false],
            ]
            : [
                ['code' => 'SC-FNB', 'name' => 'F&B Service Charge', 'kind' => 'service_charge', 'calc_type' => 'percent', 'rate' => '5', 'apply_to' => ['fnb'], 'priority' => 5],
                ['code' => 'CLF', 'name' => 'Cleaning Fee', 'kind' => 'fee', 'calc_type' => 'fixed_per_stay', 'rate' => '500', 'apply_to' => ['add_ons'], 'priority' => 50, 'is_active' => false],
            ];

        foreach ($rules as $rule) {
            if (! TaxRule::query()->forProperty($property->id)->where('code', $rule['code'])->exists()) {
                $service->create($rule);
            }
        }
    }
}
