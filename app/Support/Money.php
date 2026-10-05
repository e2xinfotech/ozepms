<?php

namespace App\Support;

use App\Models\Currency;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Decimal arithmetic for money and rates, on top of bcmath.
 *
 * Amounts are always decimal strings ("4500.00"). Intermediate results keep
 * SCALE digits so a chain of operations is not rounded early; call round() or
 * forCurrency() once, when a figure is final.
 */
final class Money
{
    /** Digits kept by intermediate results. */
    public const SCALE = 10;

    public static function normalize(string|int $value, int $scale = self::SCALE): string
    {
        $value = is_int($value) ? (string) $value : trim($value);
        if (! preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', $value)) {
            throw new InvalidArgumentException("Not a decimal amount: \"{$value}\".");
        }

        return bcadd(ltrim($value, '+'), '0', $scale);
    }

    public static function add(string|int ...$values): string
    {
        $total = '0';
        foreach ($values as $value) {
            $total = bcadd($total, self::normalize($value), self::SCALE);
        }

        return $total;
    }

    public static function sub(string|int $a, string|int $b): string
    {
        return bcsub(self::normalize($a), self::normalize($b), self::SCALE);
    }

    public static function mul(string|int $a, string|int $b): string
    {
        return bcmul(self::normalize($a), self::normalize($b), self::SCALE);
    }

    public static function div(string|int $a, string|int $b): string
    {
        $divisor = self::normalize($b);
        if (bccomp($divisor, '0', self::SCALE) === 0) {
            throw new InvalidArgumentException('Division by zero.');
        }

        return bcdiv(self::normalize($a), $divisor, self::SCALE);
    }

    /** $rate percent of $amount: percent('4500', '18') = 810. */
    public static function percent(string|int $amount, string|int $rate): string
    {
        return bcdiv(self::mul($amount, $rate), '100', self::SCALE);
    }

    /** Applies a percentage change: adjustPercent('100', '-10') = 90. */
    public static function adjustPercent(string|int $amount, string|int $rate): string
    {
        return self::add($amount, self::percent($amount, $rate));
    }

    /** Rounds half away from zero to $places decimals. */
    public static function round(string|int $value, int $places = 2): string
    {
        $value = self::normalize($value);
        $half = '0.'.str_repeat('0', $places).'5';
        $rounded = self::isNegative($value)
            ? bcsub($value, $half, $places)
            : bcadd($value, $half, $places);

        // bcmath can return "-0.00"; normalise it.
        return bccomp($rounded, '0', $places) === 0 ? bcadd('0', '0', $places) : $rounded;
    }

    /** Rounds to the currency's minor units (2 for INR/AED/EUR, 0 for JPY). */
    public static function forCurrency(string|int $value, string $currency): string
    {
        return self::round($value, self::minorUnits($currency));
    }

    public static function minorUnits(string $currency): int
    {
        $currency = strtoupper($currency);

        return (int) Cache::remember('money:minor_units:'.$currency, 86400, function () use ($currency) {
            return Currency::query()->where('code', $currency)->value('minor_units') ?? 2;
        });
    }

    public static function compare(string|int $a, string|int $b): int
    {
        return bccomp(self::normalize($a), self::normalize($b), self::SCALE);
    }

    public static function equals(string|int $a, string|int $b): bool
    {
        return self::compare($a, $b) === 0;
    }

    public static function isZero(string|int $value): bool
    {
        return self::compare($value, '0') === 0;
    }

    public static function isNegative(string|int $value): bool
    {
        return self::compare($value, '0') < 0;
    }

    public static function isPositive(string|int $value): bool
    {
        return self::compare($value, '0') > 0;
    }

    public static function negate(string|int $value): string
    {
        return bcmul(self::normalize($value), '-1', self::SCALE);
    }

    public static function abs(string|int $value): string
    {
        return self::isNegative($value) ? self::negate($value) : self::normalize($value);
    }

    public static function max(string|int $a, string|int $b): string
    {
        return self::compare($a, $b) >= 0 ? self::normalize($a) : self::normalize($b);
    }

    public static function min(string|int $a, string|int $b): string
    {
        return self::compare($a, $b) <= 0 ? self::normalize($a) : self::normalize($b);
    }

    /** @param  iterable<string|int>  $values */
    public static function sum(iterable $values): string
    {
        $total = '0';
        foreach ($values as $value) {
            $total = bcadd($total, self::normalize($value), self::SCALE);
        }

        return $total;
    }

    /** True for strings such as "12", "12.5", "-3.25". */
    public static function isDecimal(mixed $value): bool
    {
        return (is_string($value) || is_int($value)) && preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', trim((string) $value)) === 1;
    }
}
