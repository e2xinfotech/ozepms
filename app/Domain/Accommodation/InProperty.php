<?php

namespace App\Domain\Accommodation;

use App\Models\Property;
use App\Support\PropertyContext;
use Closure;

/**
 * Runs code with a given property selected, then restores whatever was selected before.
 * Needed by event listeners and seeders that work on a property outside its request.
 */
final class InProperty
{
    public static function run(Property $property, Closure $callback): mixed
    {
        $context = app(PropertyContext::class);
        $previous = $context->has()
            ? [$context->property(), $context->membership(), $context->isSupportMode()]
            : null;

        $context->set($property, null, true);

        try {
            return $callback();
        } finally {
            $previous ? $context->set(...$previous) : $context->clear();
        }
    }
}
