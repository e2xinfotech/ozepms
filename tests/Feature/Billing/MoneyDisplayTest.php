<?php

namespace Tests\Feature\Billing;

use App\Support\Money;
use Tests\TestCase;

class MoneyDisplayTest extends TestCase
{
    public function test_display_groups_thousands_with_the_currency_decimals(): void
    {
        $this->assertSame('AED 2,745.75', Money::display('2745.75', 'AED'));
        $this->assertSame('INR 1,234,567.50', Money::display('1234567.5', 'INR'));
        $this->assertSame('AED 999.00', Money::display('999', 'AED'));
    }
}
