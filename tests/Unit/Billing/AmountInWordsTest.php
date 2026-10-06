<?php

namespace Tests\Unit\Billing;

use App\Domain\Billing\AmountInWords;
use PHPUnit\Framework\TestCase;

class AmountInWordsTest extends TestCase
{
    public function test_indian_grouping(): void
    {
        $this->assertSame('Rupees One Thousand Three Hundred Fifty and Fifty Paise Only', AmountInWords::format('1350.50', 'INR'));
        $this->assertSame('Rupees Twelve Lakh Thirty Four Thousand Five Hundred Sixty Seven Only', AmountInWords::format('1234567.00', 'INR'));
        $this->assertSame('Rupees One Crore Only', AmountInWords::format('10000000', 'INR'));
        $this->assertSame('Rupees Zero Only', AmountInWords::format('0.00', 'INR'));
    }

    public function test_international_grouping(): void
    {
        $this->assertSame('AED One Million Two Hundred Thirty Four Thousand Five Hundred Sixty Seven and 05/100 Only', AmountInWords::format('1234567.05', 'AED'));
        $this->assertSame('Minus EUR Twenty One Only', AmountInWords::format('-21', 'EUR'));
    }
}
