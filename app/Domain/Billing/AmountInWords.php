<?php

namespace App\Domain\Billing;

/**
 * Amount in words for invoices (English): Indian grouping (lakh, crore) for INR, international
 * grouping (thousand, million, billion) otherwise.
 *   AmountInWords::format('1350.50', 'INR') = "Rupees One Thousand Three Hundred Fifty and Fifty Paise Only"
 */
final class AmountInWords
{
    private const ONES = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve',
        'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];

    private const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    public static function format(string $amount, string $currency): string
    {
        $amount = ltrim(trim($amount), '+');
        $negative = str_starts_with($amount, '-');
        $amount = ltrim($amount, '-');
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');
        $whole = ltrim($whole, '0') === '' ? '0' : ltrim($whole, '0');
        $minor = (int) substr(str_pad($fraction, 2, '0'), 0, 2);
        $indian = strtoupper($currency) === 'INR';

        $words = $whole === '0' ? 'Zero' : ($indian ? self::indian($whole) : self::international($whole));
        $text = ($indian ? 'Rupees ' : strtoupper($currency).' ').$words;
        if ($minor > 0) {
            $text .= ' and '.($indian ? self::belowHundred($minor).' Paise' : str_pad((string) $minor, 2, '0', STR_PAD_LEFT).'/100');
        }

        return ($negative ? 'Minus ' : '').$text.' Only';
    }

    private static function belowHundred(int $n): string
    {
        if ($n < 20) {
            return self::ONES[$n];
        }

        return trim(self::TENS[intdiv($n, 10)].' '.self::ONES[$n % 10]);
    }

    private static function belowThousand(int $n): string
    {
        $out = [];
        if ($n >= 100) {
            $out[] = self::ONES[intdiv($n, 100)].' Hundred';
            $n %= 100;
        }
        if ($n > 0) {
            $out[] = self::belowHundred($n);
        }

        return implode(' ', $out);
    }

    /** Works on digit strings so amounts above PHP_INT_MAX never overflow. */
    private static function indian(string $digits): string
    {
        $parts = [];
        $crore = strlen($digits) > 7 ? substr($digits, 0, -7) : '';
        $rest = (int) substr($digits, -7);
        if ($crore !== '' && ltrim($crore, '0') !== '') {
            $parts[] = self::indian(ltrim($crore, '0')).' Crore';
        }
        foreach ([[100000, 'Lakh'], [1000, 'Thousand']] as [$unit, $name]) {
            if ($rest >= $unit) {
                $parts[] = self::belowHundred(intdiv($rest, $unit)).' '.$name;
                $rest %= $unit;
            }
        }
        if ($rest > 0) {
            $parts[] = self::belowThousand($rest);
        }

        return implode(' ', $parts);
    }

    private static function international(string $digits): string
    {
        $groups = str_split(str_pad($digits, (int) ceil(strlen($digits) / 3) * 3, '0', STR_PAD_LEFT), 3);
        $names = ['', 'Thousand', 'Million', 'Billion', 'Trillion', 'Quadrillion'];
        $parts = [];
        $count = count($groups);
        foreach ($groups as $i => $group) {
            $n = (int) $group;
            if ($n > 0) {
                $parts[] = trim(self::belowThousand($n).' '.($names[$count - 1 - $i] ?? ''));
            }
        }

        return implode(' ', $parts);
    }
}
