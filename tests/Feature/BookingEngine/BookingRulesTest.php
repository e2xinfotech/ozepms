<?php

namespace Tests\Feature\BookingEngine;

use Illuminate\Support\Facades\DB;

/**
 * Client rules for the booking engine: rooms whose rates cannot be sold for the search are not
 * shown (no rooms left, closed to arrival / departure, min / max stay, stop sell); taxes are shown
 * by name; rate plans say clearly whether they are refundable and include breakfast; cancellation
 * terms are spelled out before booking.
 */
class BookingRulesTest extends BookingEngineTestCase
{
    private function names(array $q = []): array
    {
        DB::table('properties')->where('id', $this->property->id)->increment('ari_version');

        return array_column($this->engine()->search($this->property, $this->stayQuery(5, 7, $q))['room_types'], 'name');
    }

    private function close(array $data): void
    {
        $this->apply(array_merge(['product_ids' => [$this->steBar->id]], $data));
    }

    public function test_restrictions_hide_the_room(): void
    {
        $this->assertSame(['Deluxe Room', 'Suite'], $this->names());

        $this->close(['date_from' => $this->day(5)->toDateString(), 'date_to' => $this->day(5)->toDateString(), 'cta' => true]);
        $this->assertSame(['Deluxe Room'], $this->names(), 'closed to arrival on the check-in day');
        $this->close(['date_from' => $this->day(5)->toDateString(), 'date_to' => $this->day(5)->toDateString(), 'cta' => false]);

        $this->close(['date_from' => $this->day(7)->toDateString(), 'date_to' => $this->day(7)->toDateString(), 'ctd' => true]);
        $this->assertSame(['Deluxe Room'], $this->names(), 'closed to departure on the check-out day');
        $this->close(['date_from' => $this->day(7)->toDateString(), 'date_to' => $this->day(7)->toDateString(), 'ctd' => false]);

        $this->close(['date_from' => $this->day(5)->toDateString(), 'date_to' => $this->day(6)->toDateString(), 'min_los' => 3]);
        $this->assertSame(['Deluxe Room'], $this->names(), 'minimum stay 3 nights, search is 2');
        $this->close(['date_from' => $this->day(5)->toDateString(), 'date_to' => $this->day(6)->toDateString(), 'min_los' => 1, 'max_los' => 1]);
        $this->assertSame(['Deluxe Room'], $this->names(), 'maximum stay 1 night, search is 2');
        $this->close(['date_from' => $this->day(5)->toDateString(), 'date_to' => $this->day(6)->toDateString(), 'max_los' => 30, 'stop_sell' => true]);
        $this->assertSame(['Deluxe Room'], $this->names(), 'stop sell');
        $this->close(['date_from' => $this->day(5)->toDateString(), 'date_to' => $this->day(6)->toDateString(), 'stop_sell' => false]);
        $this->assertSame(['Deluxe Room', 'Suite'], $this->names());

        // Not enough rooms left for the number of rooms searched (suite has one room).
        $this->assertSame(['Deluxe Room'], $this->names(['rooms' => 2]));
        // Too many guests for every room type.
        $this->assertSame([], $this->names(['adults' => 9]));
    }

    public function test_taxes_by_name_and_clear_rate_plan_facts(): void
    {
        $r = $this->engine()->search($this->property, $this->stayQuery());
        $rate = $r['room_types'][0]['rates'][0];
        $this->assertNotEmpty($rate['tax_lines']);
        $this->assertSame($rate['taxes'], \App\Support\Money::round(\App\Support\Money::sum(array_column($rate['tax_lines'], 'amount'))), 'tax lines add up to the taxes');
        foreach ($rate['tax_lines'] as $line) {
            $this->assertNotSame('', $line['name']);
        }
        $this->assertIsBool($rate['refundable']);
        $this->assertIsBool($rate['breakfast']);
        $this->assertNotEmpty($rate['policy_lines']);
        $this->assertContains($rate['payment_type'], ['pay_at_property', 'prepay_full', 'deposit_percent', 'deposit_nights']);
    }

    public function test_non_refundable_plan_is_labelled_and_explained(): void
    {
        $policy = DB::table('cancellation_policies')->insertGetId(['property_id' => $this->property->id, 'code' => 'NRFX', 'name' => 'Non-refundable', 'is_refundable' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cancellation_policy_rules')->insert([
            ['policy_id' => $policy, 'applies_to' => 'cancellation', 'hours_before_arrival' => 8760, 'charge_type' => 'full', 'charge_value' => null],
            ['policy_id' => $policy, 'applies_to' => 'no_show', 'hours_before_arrival' => 0, 'charge_type' => 'full', 'charge_value' => null],
        ]);
        $nrf = $this->plan('NRFX', ['cancellation_policy_id' => $policy, 'payment_type' => 'prepay_full']);
        $this->product($this->deluxe, $nrf, ['default_price' => '3500.00']);

        $rates = collect($this->engine()->search($this->property, $this->stayQuery())['room_types'])->firstWhere('name', 'Deluxe Room')['rates'];
        $nrfRate = collect($rates)->firstWhere('name', 'NRFX');
        $this->assertFalse($nrfRate['refundable']);
        $this->assertSame(__('booking.policy.non_refundable_text'), $nrfRate['policy_lines'][0]);
        $this->assertContains(__('booking.policy.no_show', ['charge' => __('booking.policy.charges.full')]), $nrfRate['policy_lines']);
        $this->assertSame($nrfRate['grand_total'], $nrfRate['pay_now'], 'prepaid: the whole stay is paid online');
        $this->assertSame('NRFX', $rates[0]['name'], 'cheapest first');
    }
}
