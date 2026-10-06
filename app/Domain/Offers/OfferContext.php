<?php

namespace App\Domain\Offers;

use Carbon\CarbonImmutable;

/**
 * Who books, when and through which channel — everything an offer can depend on besides the rooms.
 * channel: 'pms' (front desk) or 'booking_engine'.
 */
final class OfferContext
{
    public function __construct(
        public readonly CarbonImmutable $bookedOn,
        public readonly string $channel = 'pms',
        public readonly ?string $promoCode = null,
        public readonly ?string $sourceCode = null,
        public readonly ?string $guestCountry = null,
    ) {}

    public function promo(): ?string
    {
        $code = $this->promoCode !== null ? strtoupper(trim($this->promoCode)) : '';

        return $code === '' ? null : $code;
    }
}
