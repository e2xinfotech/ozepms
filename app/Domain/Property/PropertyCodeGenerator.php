<?php

namespace App\Domain\Property;

use App\Models\Property;

/**
 * Property codes are short and readable (P1001, P1002 …). They are derived from the
 * primary key, so they are unique and never reused, even after a property is removed.
 */
final class PropertyCodeGenerator
{
    public static function for(Property $property): string
    {
        $prefix = config('ozepms.property.code_prefix');
        $start = (int) config('ozepms.property.code_start');

        return $prefix.($start - 1 + $property->id);
    }
}
