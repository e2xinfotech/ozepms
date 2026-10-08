<?php

namespace App\Domain\Tax\Reference;

use App\Models\TaxCategory;
use App\Models\TaxRule;

/**
 * Tax categories and country tax templates. Rates are data: they can be changed in
 * the Taxes & Fees screen without code changes. India GST for hotel accommodation is
 * slab based on the tariff per unit per night (rates effective 22 Sep 2025).
 */
final class TaxReference
{
    /** code => [name, SAC/HSN] */
    public const CATEGORIES = [
        'accommodation' => ['Accommodation', '9963'],
        'food' => ['Food & Beverage', '996331'],
        'beverage' => ['Beverages', '996331'],
        'alcohol' => ['Alcohol & liquor', '996332'],
        'service' => ['Other Services', null],
        'other' => ['Other', null],
    ];

    /** Which "apply to" bucket each category belongs to (Taxes & Fees screen). */
    public const CATEGORY_APPLY_TO = [
        'accommodation' => 'room_charges',
        'food' => 'fnb',
        'beverage' => 'beverage',
        'alcohol' => 'liquor',
        'service' => 'add_ons',
        'other' => 'add_ons',
    ];

    /** Inverse of CATEGORY_APPLY_TO, used to pick a rule's primary category. */
    public const APPLY_TO_CATEGORY = [
        'room_charges' => 'accommodation',
        'fnb' => 'food',
        'beverage' => 'beverage',
        'liquor' => 'alcohol',
        'add_ons' => 'service',
        'events' => 'other',
    ];

    public const INDIA_GST_EFFECTIVE_FROM = '2025-09-22';

    /** @return list<array<string, mixed>> India GST slabs for accommodation (country template rows). */
    public static function indiaGstSlabs(): array
    {
        $base = [
            'country_iso2' => 'IN', 'name' => 'GST', 'tax_type' => 'gst', 'kind' => 'tax', 'apply_to' => 'room_charges',
            'calc_type' => 'percent', 'slab_basis' => 'unit_night_tariff', 'component_mode' => 'gst_split',
            'is_inclusive' => false, 'is_compound' => false, 'priority' => 10,
            'effective_from' => self::INDIA_GST_EFFECTIVE_FROM, 'effective_to' => null, 'is_active' => true,
            'description' => 'GST on hotel accommodation (SAC 9963), by tariff per unit per night.',
        ];

        return [
            $base + ['code' => 'GST-ROOM-0', 'rate' => '0.0000', 'slab_min' => null, 'slab_max' => '1000.00'],
            $base + ['code' => 'GST-ROOM-5', 'rate' => '5.0000', 'slab_min' => '1000.01', 'slab_max' => '7500.00'],
            $base + ['code' => 'GST-ROOM-18', 'rate' => '18.0000', 'slab_min' => '7500.01', 'slab_max' => null],
        ];
    }

    public static function seedCategories(): void
    {
        foreach (array_keys(self::CATEGORIES) as $code) {
            self::category($code);
        }
    }

    /** Returns the category, creating it when the reference data has not been seeded. */
    public static function category(string $code): TaxCategory
    {
        [$name, $sac] = self::CATEGORIES[$code] ?? [ucfirst($code), null];

        return TaxCategory::query()->firstOrCreate(['code' => $code], ['name' => $name, 'default_sac_hsn' => $sac]);
    }

    /** Country templates (property_id NULL). Idempotent by country + code. */
    public static function seedCountryTemplates(): void
    {
        $categoryId = self::category('accommodation')->id;
        foreach (self::indiaGstSlabs() as $row) {
            $rule = TaxRule::query()->templatesFor('IN')->where('code', $row['code'])->first() ?? new TaxRule;
            $rule->fill($row + ['tax_category_id' => $categoryId, 'property_id' => null])->save();
        }
    }

    /** Copies the country's templates into the property's own rules (property defaults). */
    public static function copyTemplatesToProperty(int $propertyId, string $countryIso2): int
    {
        if ($countryIso2 === 'IN' && ! TaxRule::query()->templatesFor('IN')->exists()) {
            self::seedCountryTemplates();
        }

        $copied = 0;
        TaxRule::query()->templatesFor($countryIso2)->where('is_active', true)->get()
            ->each(function (TaxRule $template) use ($propertyId, &$copied) {
                $exists = TaxRule::query()->forProperty($propertyId)->where('code', $template->code)->exists();
                if ($exists) {
                    return;
                }
                $copy = $template->replicate(['public_id', 'created_at', 'updated_at']);
                $copy->property_id = $propertyId;
                $copy->save();
                $copied++;
            });

        return $copied;
    }
}
