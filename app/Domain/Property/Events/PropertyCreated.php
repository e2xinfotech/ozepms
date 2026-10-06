<?php

namespace App\Domain\Property\Events;

use App\Models\Property;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after a property, its owner and first subscription are created (inside the transaction).
 * $copiedFromId is set when the property is a copy: its rates, rooms and taxes come from the source.
 */
final class PropertyCreated
{
    use Dispatchable;

    public function __construct(
        public readonly Property $property,
        public readonly ?int $copiedFromId = null,
    ) {}
}
