<?php

namespace Tests\Unit\Tax;

use App\Domain\Tax\TaxService;
use App\Models\Property;
use App\Models\State;
use App\Models\TaxRule;
use Database\Seeders\AccommodationReferenceSeeder;
use Tests\TestCase;

/** India GST on accommodation: slabs by tariff per room-night, CGST + SGST split, IGST only for non-accommodation inter-state supplies. */
class TaxServiceGstTest extends TestCase
{
    private Property $property;

    private TaxService $taxes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccommodationReferenceSeeder::class);
        [$this->property] = $this->createPropertyWithOwner(['state_id' => State::query()->where('code', 'IN-MH')->value('id')]);
        $this->taxes = app(TaxService::class);
    }

    private function room(string $tariff, int $nights = 1, string $date = '2026-10-10'): array
    {
        return ['category' => 'accommodation', 'amount' => bcmul($tariff, (string) $nights, 2), 'unit_night_tariff' => $tariff, 'date' => $date, 'nights' => $nights, 'persons' => 2];
    }

    /** @return array<string, string> */
    private function gst(string $tariff, ?string $guestState = null): array
    {
        return $this->taxes->calculate($this->property, [$this->room($tariff)], $guestState)->byComponent();
    }

    public function test_slab_boundaries(): void
    {
        $this->assertSame(['CGST' => '0.00', 'SGST' => '0.00'], $this->gst('1000.00'));
        $this->assertSame(['CGST' => '25.00', 'SGST' => '25.00'], $this->gst('1000.01'));
        $this->assertSame(['CGST' => '187.50', 'SGST' => '187.50'], $this->gst('7500.00'));
        $this->assertSame(['CGST' => '675.00', 'SGST' => '675.00'], $this->gst('7500.01'));
        $this->assertSame(['CGST' => '0.00', 'SGST' => '0.00'], $this->gst('999.99'));
    }

    public function test_split_rates_and_rounding(): void
    {
        $result = $this->taxes->calculate($this->property, [$this->room('4999.99')]);
        $components = $result->lines[0]['components'];

        $this->assertSame(['CGST', 'SGST'], array_column($components, 'component'));
        $this->assertSame('2.5000', $components[0]['rate']);
        $this->assertSame('125.00', $components[0]['amount']);
        $this->assertSame('125.00', $components[1]['amount']);
        $this->assertSame('250.00', $result->taxTotal);
        $this->assertSame('4999.99', $result->taxableTotal);
        $this->assertSame('INR', $result->currency);
    }

    public function test_slab_uses_tariff_per_night_not_stay_total(): void
    {
        $result = $this->taxes->calculate($this->property, [$this->room('900.00', 3)]);

        $this->assertSame('2700.00', $result->taxableTotal);
        $this->assertSame('0.00', $result->taxTotal);

        // Without an explicit tariff it is derived from amount / nights.
        $line = ['category' => 'accommodation', 'amount' => '24000.00', 'date' => '2026-10-10', 'nights' => 3];
        $this->assertSame('4320.00', $this->taxes->calculate($this->property, [$line])->taxTotal);
    }

    public function test_accommodation_stays_cgst_sgst_for_guests_from_another_state(): void
    {
        $this->assertSame(['CGST' => '112.50', 'SGST' => '112.50'], $this->gst('4500.00', 'IN-KA'));
    }

    public function test_other_supplies_to_another_state_use_igst(): void
    {
        $this->inProperty($this->property);
        TaxRule::query()->create([
            'property_id' => $this->property->id, 'country_iso2' => 'IN', 'tax_category_id' => \App\Domain\Tax\Reference\TaxReference::category('service')->id,
            'code' => 'GST-SVC', 'name' => 'GST', 'tax_type' => 'gst', 'kind' => 'tax', 'apply_to' => 'add_ons', 'calc_type' => 'percent',
            'rate' => '18', 'component_mode' => 'gst_split', 'effective_from' => '2025-01-01', 'is_active' => true,
        ]);
        $line = ['category' => 'service', 'amount' => '1000.00', 'date' => '2026-10-10'];

        $this->assertSame(['IGST' => '180.00'], $this->taxes->calculate($this->property, [$line], 'IN-KA')->byComponent());
        $this->assertSame(['CGST' => '90.00', 'SGST' => '90.00'], $this->taxes->calculate($this->property, [$line], '27')->byComponent());
    }

    public function test_rules_outside_their_dates_are_ignored(): void
    {
        $this->assertSame('0.00', $this->taxes->calculate($this->property, [$this->room('5000.00', 1, '2025-09-01')])->taxTotal);
    }

    public function test_inclusive_price_is_back_calculated(): void
    {
        TaxRule::query()->where('property_id', $this->property->id)->update(['is_inclusive' => true]);

        $result = $this->taxes->calculate($this->property, [$this->room('5250.00')]);
        $this->assertSame('5000.00', $result->taxableTotal);
        $this->assertSame('250.00', $result->taxTotal);
    }

    public function test_properties_without_rules_fall_back_to_country_templates(): void
    {
        TaxRule::query()->where('property_id', $this->property->id)->delete();

        $this->assertSame(['CGST' => '900.00', 'SGST' => '900.00'], $this->gst('10000.00'));
    }
}
