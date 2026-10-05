<?php

namespace App\Domain\Offers;

/**
 * Output of OfferService::apply(). Amounts are decimal strings.
 * offers: [['offer_id' => int, 'name' => string, 'code' => ?string, 'discount' => '450.00', 'nightly' => ['2026-10-04' => '150.00', ...]]]
 */
final class AppliedOffers
{
    public function __construct(public readonly array $offers, public readonly string $discountTotal) {}

    public static function none(): self
    {
        return new self([], '0.00');
    }
}
