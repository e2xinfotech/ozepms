<?php

namespace App\Domain\Tax;

use App\Domain\Tax\Reference\TaxReference;
use App\Models\TaxRule;
use App\Support\Money;
use InvalidArgumentException;

/** One validated input line of TaxService::calculate(). */
final class TaxLine
{
    private function __construct(
        public readonly string $category,
        public readonly string $amount,
        public readonly string $date,
        public readonly string $unitNightTariff,
        public readonly ?int $roomTypeId,
        public readonly ?int $ratePlanId,
        public readonly int $nights,
        public readonly int $persons,
    ) {}

    /** @param  array<string, mixed>  $line */
    public static function from(array $line): self
    {
        foreach (['category', 'amount', 'date'] as $key) {
            if (! isset($line[$key]) || $line[$key] === '') {
                throw new InvalidArgumentException("Tax line is missing \"{$key}\".");
            }
        }
        if (! Money::isDecimal($line['amount'])) {
            throw new InvalidArgumentException('Tax line amount must be a decimal string.');
        }
        $date = (string) $line['date'];
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException('Tax line date must be Y-m-d.');
        }

        $nights = max(1, (int) ($line['nights'] ?? 1));
        $amount = Money::normalize((string) $line['amount']);
        $tariff = isset($line['unit_night_tariff']) && Money::isDecimal($line['unit_night_tariff'])
            ? Money::normalize((string) $line['unit_night_tariff'])
            : Money::div($amount, $nights);

        return new self(
            category: (string) $line['category'],
            amount: $amount,
            date: $date,
            unitNightTariff: $tariff,
            roomTypeId: isset($line['room_type_id']) ? (int) $line['room_type_id'] : null,
            ratePlanId: isset($line['rate_plan_id']) ? (int) $line['rate_plan_id'] : null,
            nights: $nights,
            persons: max(1, (int) ($line['persons'] ?? 1)),
        );
    }

    /** The "apply to" bucket of this line (room_charges, add_ons, fnb, events). */
    public function bucket(): string
    {
        if (in_array($this->category, TaxRule::APPLY_TO, true)) {
            return $this->category;
        }

        return TaxReference::CATEGORY_APPLY_TO[$this->category] ?? 'add_ons';
    }

    public function isAccommodation(): bool
    {
        return $this->bucket() === 'room_charges';
    }
}
