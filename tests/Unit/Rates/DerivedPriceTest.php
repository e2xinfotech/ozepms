<?php

namespace Tests\Unit\Rates;

use App\Domain\Rates\DerivedPrice;
use App\Models\Product;
use App\Models\RoomType;
use InvalidArgumentException;
use Tests\TestCase;

class DerivedPriceTest extends TestCase
{
    public function test_percent_fixed_and_per_person_adjustments(): void
    {
        $this->assertSame('4500.00', DerivedPrice::apply('5000.00', 'percent', '-10'));
        $this->assertSame('5750.00', DerivedPrice::apply('5000.00', 'percent', '15'));
        $this->assertSame('4500.00', DerivedPrice::apply('5000.00', 'fixed', '-500'));
        $this->assertSame('5600.00', DerivedPrice::apply('5000.00', 'fixed_per_person', '300', 2));
        $this->assertSame('3333.33', DerivedPrice::apply('3703.70', 'percent', '-10'));
    }

    public function test_price_never_goes_below_zero(): void
    {
        $this->assertSame('0.00', DerivedPrice::apply('100.00', 'fixed', '-250'));
    }

    public function test_unknown_type_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DerivedPrice::apply('100', 'double', '2');
    }

    public function test_base_price_follows_the_chain_of_parents(): void
    {
        $roomType = new RoomType(['base_adults' => 2]);
        $bar = new Product(['pricing_mode' => 'manual', 'default_price' => '5000.00']);
        $nrf = new Product(['pricing_mode' => 'derived', 'adjust_type' => 'percent', 'adjust_value' => '-10']);
        $nrfPromo = new Product(['pricing_mode' => 'derived', 'adjust_type' => 'fixed_per_person', 'adjust_value' => '-100']);
        foreach ([$bar, $nrf, $nrfPromo] as $p) {
            $p->setRelation('roomType', $roomType);
        }
        $nrf->setRelation('parent', $bar);
        $nrfPromo->setRelation('parent', $nrf);

        $this->assertSame('5000.00', DerivedPrice::basePrice($bar));
        $this->assertSame('4500.00', DerivedPrice::basePrice($nrf));
        $this->assertSame('4300.00', DerivedPrice::basePrice($nrfPromo));
    }

    public function test_base_price_is_null_without_a_manual_price(): void
    {
        $this->assertNull(DerivedPrice::basePrice(new Product(['pricing_mode' => 'manual', 'default_price' => null])));
    }
}
