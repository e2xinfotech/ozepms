<?php

namespace App\Domain\Pricing\Exceptions;

use RuntimeException;

/** A night of the stay has no price (no daily row, or the manual parent has none). */
final class NoRateException extends RuntimeException
{
    /** @param  list<string>  $dates */
    public function __construct(public readonly int $productId, public readonly array $dates = [])
    {
        parent::__construct(__('inventory.reasons.no_rate'));
    }
}
