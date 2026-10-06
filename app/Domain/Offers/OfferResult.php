<?php

namespace App\Domain\Offers;

/**
 * Output of OfferService::evaluate(). Amounts are decimal strings in the property currency.
 *
 * rooms[key] = ['nights' => [date => discount], 'offers' => [offer_id => ['amount' => '..', 'nightly' => [date => '..']]]]
 * applied    = list of ['offer_id', 'id' (public), 'name', 'code', 'promo_code', 'offer_type', 'discount_type', 'discount_value', 'amount']
 * promo      = null, or ['code' => 'SUMMER', 'status' => 'applied'|'unknown'|'not_eligible', 'reason' => ?string (translation key)]
 */
final class OfferResult
{
    public function __construct(
        public readonly array $rooms,
        public readonly array $applied,
        public readonly string $total,
        public readonly ?array $promo = null,
    ) {}

    public static function none(?array $promo = null): self
    {
        return new self([], [], '0.00', $promo);
    }

    /** Discount of one night of one room ('0.00' when none). */
    public function nightDiscount(int|string $room, string $date): string
    {
        return $this->rooms[$room]['nights'][$date] ?? '0.00';
    }

    /** @return array<int, array{amount: string, nightly: array<string, string>}> */
    public function roomOffers(int|string $room): array
    {
        return $this->rooms[$room]['offers'] ?? [];
    }
}
