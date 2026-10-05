<?php

namespace App\Domain\Accommodation\Reference;

use App\Models\Amenity;
use App\Models\BedType;

/**
 * Global bed types and amenities. Used by Phase2ReferenceSeeder; safe to run repeatedly.
 * Icon names must exist in resources/js/components/ui/Icon.tsx.
 */
final class AccommodationReference
{
    /** code => sleeps */
    public const BED_TYPES = [
        'king' => 2, 'queen' => 2, 'double' => 2, 'single' => 1, 'twin' => 2,
        'sofa_bed' => 1, 'bunk_bed' => 2, 'futon' => 1, 'crib' => 1,
    ];

    /** code => [category, icon] */
    public const AMENITIES = [
        'wifi' => ['room', 'wifi'],
        'air_conditioning' => ['room', 'snowflake'],
        'heating' => ['room', 'heater'],
        'ceiling_fan' => ['room', 'fan'],
        'desk' => ['room', 'briefcase'],
        'wardrobe' => ['room', 'shirt'],
        'seating_area' => ['room', 'armchair'],
        'balcony' => ['room', 'sun'],
        'sea_view' => ['room', 'waves'],
        'mountain_view' => ['room', 'mountain'],
        'safe' => ['room', 'vault'],
        'telephone' => ['room', 'phone'],
        'iron' => ['room', 'shirt'],
        'soundproofing' => ['room', 'door-closed'],
        'blackout_curtains' => ['room', 'moon'],
        'baby_cot' => ['room', 'baby'],
        'private_bathroom' => ['bathroom', 'bath'],
        'bathtub' => ['bathroom', 'bath'],
        'shower' => ['bathroom', 'shower-head'],
        'hair_dryer' => ['bathroom', 'fan'],
        'toiletries' => ['bathroom', 'sparkles'],
        'bathrobe' => ['bathroom', 'shirt'],
        'tv' => ['media', 'tv'],
        'smart_tv' => ['media', 'tv'],
        'satellite_channels' => ['media', 'globe'],
        'refrigerator' => ['kitchen', 'refrigerator'],
        'minibar' => ['kitchen', 'wine'],
        'coffee_maker' => ['kitchen', 'coffee'],
        'kettle' => ['kitchen', 'coffee'],
        'microwave' => ['kitchen', 'microwave'],
        'kitchenette' => ['kitchen', 'utensils'],
        'dining_area' => ['kitchen', 'utensils-crossed'],
        'washing_machine' => ['kitchen', 'washing-machine'],
        'parking' => ['property', 'square-parking'],
        'swimming_pool' => ['property', 'waves'],
        'fitness_center' => ['property', 'dumbbell'],
        'restaurant' => ['property', 'utensils-crossed'],
        'bar' => ['property', 'wine'],
        'spa' => ['property', 'sparkles'],
        'wheelchair_accessible' => ['property', 'accessibility'],
        'non_smoking' => ['property', 'cigarette-off'],
        'room_service' => ['service', 'concierge-bell'],
        'daily_housekeeping' => ['service', 'spray-can'],
        'laundry' => ['service', 'washing-machine'],
        'airport_shuttle' => ['service', 'plane'],
        'front_desk_24h' => ['service', 'clock'],
    ];

    public static function seedBedTypes(): void
    {
        foreach (self::BED_TYPES as $code => $sleeps) {
            BedType::query()->updateOrCreate(['code' => $code], ['label_key' => 'rooms.beds.'.$code, 'sleeps' => $sleeps]);
        }
    }

    public static function seedAmenities(): void
    {
        foreach (self::AMENITIES as $code => [$category, $icon]) {
            $amenity = Amenity::query()->whereNull('property_id')->where('code', $code)->first() ?? new Amenity(['code' => $code]);
            $amenity->fill([
                'name' => ucfirst(str_replace('_', ' ', $code)),
                'label_key' => 'amenities.items.'.$code,
                'category' => $category,
                'icon' => $icon,
                'applies_to' => $category === 'property' || $category === 'service' ? 'property,room_type' : 'room_type,unit',
                'is_active' => true,
            ])->save();
        }
    }
}
