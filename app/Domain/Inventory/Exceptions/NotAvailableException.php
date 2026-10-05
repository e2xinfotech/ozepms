<?php

namespace App\Domain\Inventory\Exceptions;

use RuntimeException;

/** Thrown when inventory cannot be reserved; callers must roll back and show alternatives. */
final class NotAvailableException extends RuntimeException
{
    /** @param  array<int, string>  $dates  stay dates (Y-m-d) without availability */
    public function __construct(public readonly int $roomTypeId, public readonly array $dates = [], string $message = '')
    {
        parent::__construct($message ?: __('errors.not_available'));
    }
}
