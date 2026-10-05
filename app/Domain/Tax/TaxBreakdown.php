<?php

namespace App\Domain\Tax;

/**
 * Output of TaxService::calculate(). Amounts are decimal strings.
 *
 * lines: one entry per input line, same order:
 *   ['taxable' => '4500.00', 'tax_total' => '225.00', 'components' => [
 *       ['tax_rule_id' => 3, 'component' => 'CGST', 'name' => 'CGST', 'rate' => '2.5000', 'taxable' => '4500.00', 'amount' => '112.50', 'inclusive' => false],
 *   ]]
 */
final class TaxBreakdown
{
    /** @param  array<int, array<string, mixed>>  $lines */
    public function __construct(
        public readonly array $lines,
        public readonly string $taxableTotal,
        public readonly string $taxTotal,
        public readonly string $currency,
    ) {}

    /** Totals per component (CGST, SGST, IGST, VAT, CITY…) across all lines. */
    public function byComponent(): array
    {
        $out = [];
        foreach ($this->lines as $line) {
            foreach ($line['components'] as $c) {
                $out[$c['component']] = bcadd($out[$c['component']] ?? '0', (string) $c['amount'], 2);
            }
        }

        return $out;
    }
}
