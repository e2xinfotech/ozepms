<?php

namespace App\Domain\Rates;

use App\Models\Product;
use Illuminate\Validation\ValidationException;

/**
 * Rules for derived products: the parent belongs to the same property (guaranteed by the
 * tenant scope when it is looked up), is not the product itself and does not create a
 * cycle (A → B → A). Chains are limited so price calculation stays cheap.
 */
final class DerivedPricingGuard
{
    public const MAX_DEPTH = 5;

    public function assertValidParent(?int $productId, Product $parent, int $propertyId, string $field = 'parent'): void
    {
        if ((int) $parent->property_id !== $propertyId) {
            throw ValidationException::withMessages([$field => __('rates.errors.parent_other_property')]);
        }
        if ($productId !== null && $parent->id === $productId) {
            throw ValidationException::withMessages([$field => __('rates.errors.parent_self')]);
        }

        $depth = 1;
        $current = $parent;
        $seen = [$parent->id => true];
        while ($current->parent_product_id !== null) {
            $nextId = (int) $current->parent_product_id;
            if ($nextId === $productId || isset($seen[$nextId])) {
                throw ValidationException::withMessages([$field => __('rates.errors.parent_cycle')]);
            }
            if (++$depth >= self::MAX_DEPTH) {
                throw ValidationException::withMessages([$field => __('rates.errors.parent_too_deep', ['max' => self::MAX_DEPTH])]);
            }
            $seen[$nextId] = true;
            $current = Product::query()->findOrFail($nextId);
        }
    }
}
