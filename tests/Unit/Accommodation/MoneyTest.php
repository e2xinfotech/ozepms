<?php

namespace Tests\Unit\Accommodation;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_arithmetic_keeps_decimal_strings_exact(): void
    {
        $this->assertSame('0.30', Money::round(Money::add('0.1', '0.2'), 2));
        $this->assertSame('-0.75', Money::round(Money::sub('1.25', '2'), 2));
        $this->assertSame('810.00', Money::round(Money::percent('4500', '18'), 2));
        $this->assertSame('90.00', Money::round(Money::adjustPercent('100', '-10'), 2));
        $this->assertSame('1500.00', Money::round(Money::div('4500', 3), 2));
        $this->assertSame('6.00', Money::round(Money::sum(['1', '2.5', '2.5']), 2));
    }

    public function test_round_is_half_away_from_zero(): void
    {
        $this->assertSame('2.35', Money::round('2.345', 2));
        $this->assertSame('-2.35', Money::round('-2.345', 2));
        $this->assertSame('2.34', Money::round('2.3449', 2));
        $this->assertSame('3', Money::round('2.5', 0));
        $this->assertSame('0.00', Money::round('-0.001', 2));
    }

    public function test_comparisons(): void
    {
        $this->assertSame(0, Money::compare('1000.00', '1000'));
        $this->assertSame(1, Money::compare('1000.01', '1000'));
        $this->assertTrue(Money::isNegative('-0.01'));
        $this->assertTrue(Money::isZero('0.000'));
        $this->assertSame('5.0000000000', Money::abs('-5'));
        $this->assertSame('7.0000000000', Money::max('7', '3'));
        $this->assertSame('3.0000000000', Money::min('7', '3'));
        $this->assertTrue(Money::isDecimal('12.5'));
        $this->assertFalse(Money::isDecimal(12.5));
        $this->assertFalse(Money::isDecimal('1e3'));
    }

    public function test_invalid_input_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::add('12,50', '1');
    }

    public function test_division_by_zero_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::div('10', '0.00');
    }
}
