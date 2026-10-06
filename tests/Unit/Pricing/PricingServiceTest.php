<?php

namespace Tests\Unit\Pricing;

use App\Domain\Pricing\PricingData;
use App\Domain\Pricing\PricingService;
use App\Models\Product;
use App\Models\ProductOccupancyRule;
use App\Models\RoomType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

/** Prices from rows in memory: base, occupancy rules, occupancy prices, derived products. */
class PricingServiceTest extends TestCase
{
    private const BANDS = [
        ['id' => 1, 'code' => 'infant', 'min_age' => 0, 'max_age' => 2],
        ['id' => 2, 'code' => 'child', 'min_age' => 3, 'max_age' => 11],
        ['id' => 3, 'code' => 'teen', 'min_age' => 12, 'max_age' => 17],
    ];

    private PricingService $pricing;

    private RoomType $roomType;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricing = new PricingService;
        $this->roomType = new RoomType(['base_adults' => 2, 'max_adults' => 4]);
    }

    private function product(int $id, array $rules = [], ?Product $parent = null, ?string $type = null, ?string $value = null): Product
    {
        $product = new Product([
            'room_type_id' => 10, 'rate_plan_id' => 20 + $id,
            'pricing_mode' => $parent ? 'derived' : 'manual', 'adjust_type' => $type, 'adjust_value' => $value,
            'parent_product_id' => $parent?->id, 'inherit_restrictions' => true,
        ]);
        $product->id = $id;
        $product->setRelation('roomType', $this->roomType);
        $product->setRelation('parent', $parent);
        $product->setRelation('occupancyRules', new Collection(array_map(fn ($r) => new ProductOccupancyRule(array_merge(['age_band_id' => null, 'adjust_type' => 'fixed'], $r)), $rules)));

        return $product;
    }

    private function data(array $prices, array $occupancy = []): PricingData
    {
        $ari = [];
        foreach ($prices as $productId => $byDate) {
            foreach ($byDate as $date => $price) {
                $ari[$productId][$date] = ['price' => $price];
            }
        }

        return new PricingData($ari, $occupancy, self::BANDS, 'INR');
    }

    private function quote(Product $product, PricingData $data, int $adults = 2, array $ages = [], string $in = '2026-12-01', string $out = '2026-12-03', ?array &$missing = null)
    {
        return $this->pricing->quoteWith($product, CarbonImmutable::parse($in), CarbonImmutable::parse($out), $adults, $ages, $data, $missing);
    }

    private const TWO_NIGHTS = [1 => ['2026-12-01' => '1000.00', '2026-12-02' => '1200.00']];

    public function test_base_occupancy_uses_the_daily_price(): void
    {
        $quote = $this->quote($this->product(1), $this->data(self::TWO_NIGHTS));

        $this->assertSame('2200.00', $quote->roomTotal);
        $this->assertSame('INR', $quote->currency);
        $this->assertSame(2, $quote->nightCount());
        $this->assertSame(['date' => '2026-12-02', 'base_price' => '1200.00', 'occupancy_adjust' => '0.00', 'price' => '1200.00'], $quote->nights[1]);
    }

    public function test_single_occupancy_and_extra_adults(): void
    {
        $rules = [
            ['guest_type' => 'adult', 'guest_count' => 1, 'adjust_type' => 'percent', 'adjust_value' => '-10'],
            ['guest_type' => 'adult', 'guest_count' => 3, 'adjust_value' => '250'],
        ];
        $product = $this->product(1, $rules);
        $data = $this->data(self::TWO_NIGHTS);

        $this->assertSame(['900.00', '1080.00'], array_column($this->quote($product, $data, 1)->nights, 'price'));
        $this->assertSame('-100.00', $this->quote($product, $data, 1)->nights[0]['occupancy_adjust']);
        $this->assertSame(['1250.00', '1450.00'], array_column($this->quote($product, $data, 3)->nights, 'price'));
        // The 3rd-adult rule applies to every adult from the 3rd on.
        $this->assertSame('1500.00', $this->quote($product, $data, 4)->nights[0]['price']);

        $rules[] = ['guest_type' => 'adult', 'guest_count' => 4, 'adjust_value' => '400'];
        $this->assertSame('1650.00', $this->quote($this->product(1, $rules), $this->data(self::TWO_NIGHTS), 4)->nights[0]['price']);
    }

    public function test_children_are_priced_by_age_band(): void
    {
        $product = $this->product(1, [
            ['guest_type' => 'child', 'guest_count' => 1, 'age_band_id' => 2, 'adjust_value' => '100'],
            ['guest_type' => 'child', 'guest_count' => 1, 'age_band_id' => 3, 'adjust_type' => 'percent', 'adjust_value' => '25'],
            ['guest_type' => 'child', 'guest_count' => 3, 'adjust_value' => '50'],
            ['guest_type' => 'infant', 'guest_count' => 2, 'adjust_value' => '20'],
        ]);
        $data = $this->data(self::TWO_NIGHTS);

        $this->assertSame('1100.00', $this->quote($product, $data, 2, [6])->nights[0]['price']);
        $this->assertSame('1250.00', $this->quote($product, $data, 2, [14])->nights[0]['price'], 'teen: 25 % of the base');
        $this->assertSame('1200.00', $this->quote($product, $data, 2, [5, 8])->nights[0]['price'], 'band rule applies from the 1st child on');
        $this->assertSame('1000.00', $this->quote($product, $data, 2, [1])->nights[0]['price'], 'first infant is free');
        $this->assertSame('1020.00', $this->quote($product, $data, 2, [1, 0])->nights[0]['price'], 'second infant pays');
        // A child outside every band only matches rules without a band: 3rd child on.
        $this->assertSame('1000.00', $this->quote($this->product(1, [['guest_type' => 'child', 'guest_count' => 3, 'adjust_value' => '50']]), $data, 2, [30, 30])->nights[0]['price']);
        $this->assertSame([5, 8], $this->quote($product, $data, 2, [5, 8])->childAges);
    }

    public function test_occupancy_price_replaces_base_and_adult_rules(): void
    {
        $product = $this->product(1, [
            ['guest_type' => 'adult', 'guest_count' => 1, 'adjust_type' => 'percent', 'adjust_value' => '-10'],
            ['guest_type' => 'child', 'guest_count' => 1, 'adjust_value' => '100'],
        ]);
        $data = $this->data(self::TWO_NIGHTS, [1 => ['2026-12-01' => [1 => '700.00']]]);

        $quote = $this->quote($product, $data, 1, [7]);
        $this->assertSame(['800.00', '1180.00'], array_column($quote->nights, 'price'), 'override on the 1st night only; child rule still applies');
        $this->assertSame('1000.00', $quote->nights[0]['base_price']);
    }

    public function test_derived_products_follow_the_parent(): void
    {
        $parent = $this->product(1, [['guest_type' => 'adult', 'guest_count' => 3, 'adjust_value' => '250']]);
        $data = $this->data(self::TWO_NIGHTS);

        $nrf = $this->product(2, [], $parent, 'percent', '-10');
        $this->assertSame('1980.00', $this->quote($nrf, $data)->roomTotal);
        $this->assertSame('1125.00', $this->quote($nrf, $data, 3)->nights[0]['price'], 'parent price for the same guests, then −10 %');
        $this->assertSame('900.00', $this->quote($nrf, $data, 3)->nights[0]['base_price']);

        $fixed = $this->product(3, [], $parent, 'fixed', '-150');
        $this->assertSame('850.00', $this->quote($fixed, $data)->nights[0]['price']);

        $perPerson = $this->product(4, [], $parent, 'fixed_per_person', '100');
        $this->assertSame('1550.00', $this->quote($perPerson, $data, 3)->nights[0]['price'], '1250 + 3 × 100');
        $this->assertSame('1200.00', $this->quote($perPerson, $data, 3)->nights[0]['base_price'], 'base: 1000 + 2 base adults × 100');

        // Derived from derived.
        $chain = $this->product(5, [], $nrf, 'percent', '-50');
        $this->assertSame('450.00', $this->quote($chain, $data)->nights[0]['price']);
    }

    public function test_derived_product_with_own_rules_uses_them(): void
    {
        $parent = $this->product(1, [['guest_type' => 'adult', 'guest_count' => 1, 'adjust_value' => '-500']]);
        $derived = $this->product(2, [['guest_type' => 'adult', 'guest_count' => 1, 'adjust_type' => 'percent', 'adjust_value' => '-20']], $parent, 'percent', '-10');

        $this->assertSame('720.00', $this->quote($derived, $this->data(self::TWO_NIGHTS), 1)->nights[0]['price']);
    }

    public function test_missing_price_gives_no_quote(): void
    {
        $data = $this->data([1 => ['2026-12-01' => '1000.00', '2026-12-02' => null]]);
        $this->assertNull($this->quote($this->product(1), $data, missing: $missing));
        $this->assertSame(['2026-12-02'], $missing);

        $derived = $this->product(2, [], $this->product(1), 'percent', '-10');
        $this->assertNull($this->quote($derived, $data));
    }

    public function test_prices_never_go_below_zero_and_are_rounded(): void
    {
        $data = $this->data([1 => ['2026-12-01' => '333.33']]);
        $cheap = $this->product(2, [], $this->product(1), 'fixed', '-1000');
        $this->assertSame('0.00', $this->quote($cheap, $data, out: '2026-12-02')->roomTotal);

        $third = $this->product(3, [], $this->product(1), 'percent', '-33.333');
        $this->assertSame('222.22', $this->quote($third, $data, out: '2026-12-02')->nights[0]['price']);
    }
}
