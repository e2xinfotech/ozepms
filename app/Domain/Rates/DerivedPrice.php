<?php

namespace App\Domain\Rates;

use App\Models\Product;
use App\Support\Money;
use InvalidArgumentException;

/**
 * Price of a derived product from its parent's price:
 *   percent           parent × (1 + value / 100)          −10 → 10 % cheaper
 *   fixed             parent + value                      −500 → 500 cheaper
 *   fixed_per_person  parent + value × persons            persons = the room type's base adults
 * A result below zero is clamped to zero. Rounded to $places decimals.
 */
final class DerivedPrice
{
    public static function apply(string $parentPrice, string $type, string $value, int $persons = 1, int $places = 2): string
    {
        $price = match ($type) {
            'percent' => Money::adjustPercent($parentPrice, $value),
            'fixed' => Money::add($parentPrice, $value),
            'fixed_per_person' => Money::add($parentPrice, Money::mul($value, max(1, $persons))),
            default => throw new InvalidArgumentException("Unknown adjustment type \"{$type}\"."),
        };

        return Money::round(Money::max($price, '0'), $places);
    }

    /**
     * The product's default (base occupancy) price, following derived products up to their
     * manual ancestor. Uses loaded relations when present. Null when no price is set.
     */
    public static function basePrice(Product $product, int $places = 2): ?string
    {
        $chain = [];
        $current = $product;
        $guard = 0;
        while ($current->isDerived()) {
            if (++$guard > DerivedPricingGuard::MAX_DEPTH + 1) {
                return null;
            }
            $chain[] = $current;
            $parent = $current->relationLoaded('parent') ? $current->parent : Product::query()->find($current->parent_product_id);
            if (! $parent) {
                return null;
            }
            $current = $parent;
        }

        if ($current->default_price === null) {
            return null;
        }

        $price = (string) $current->default_price;
        foreach (array_reverse($chain) as $derived) {
            $persons = (int) ($derived->roomType?->base_adults ?? 1) ?: 1;
            $price = self::apply($price, (string) $derived->adjust_type, (string) $derived->adjust_value, $persons, $places);
        }

        return $price;
    }
}
