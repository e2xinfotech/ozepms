<?php

namespace Tests\Feature\Offers;

use App\Models\Offer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Rule matrix of OfferService (spec §32). Prices are per night, before tax. */
class OfferServiceTest extends OfferTestCase
{
    public function test_discount_types(): void
    {
        $percent = $this->offer(['discount_type' => 'percent', 'discount_value' => '20']);
        $r = $this->evaluate([$this->stay($this->dlxBar, 5, ['1000', '1000'])]);
        $this->assertSame('400.00', $r->total);
        $this->assertSame('200.00', $r->nightDiscount(0, $this->day(5)->toDateString()));
        $percent->update(['is_active' => false]);

        $this->offer(['discount_type' => 'fixed_per_night', 'discount_value' => '1500']);
        $this->assertSame('1800.00', $this->evaluate([$this->stay($this->dlxBar, 5, ['1000', '800'])])->total, 'never more than the night price');
        Offer::acrossProperties()->update(['is_active' => false]);

        $this->offer(['discount_type' => 'fixed_per_stay', 'discount_value' => '300']);
        $r = $this->evaluate([$this->stay($this->dlxBar, 5, ['1000', '2000'])]);
        $this->assertSame('300.00', $r->total);
        $this->assertSame('100.00', $r->nightDiscount(0, $this->day(5)->toDateString()), 'spread by price');
        $this->assertSame('200.00', $r->nightDiscount(0, $this->day(6)->toDateString()));
        Offer::acrossProperties()->update(['is_active' => false]);

        // Stay 3 pay 2: the cheapest night of each full block of three is free.
        $this->offer(['discount_type' => 'free_nights', 'discount_value' => '1', 'min_nights' => 3, 'offer_type' => 'package']);
        $this->assertSame('0.00', $this->evaluate([$this->stay($this->dlxBar, 5, ['1000', '1000'])])->total, 'two nights: not enough');
        $r = $this->evaluate([$this->stay($this->dlxBar, 5, ['1200', '900', '1000', '1000', '1000', '1100', '1300'])]);
        $this->assertSame('1900.00', $r->total, 'seven nights = two blocks: 900 + 1000');
    }

    public function test_windows_weekdays_and_advance(): void
    {
        $from = $this->day(6)->toDateString();
        $this->offer(['stay_from' => $from, 'stay_to' => $this->day(7)->toDateString()]);
        $r = $this->evaluate([$this->stay($this->dlxBar, 5, ['1000', '1000', '1000', '1000'])]);
        $this->assertSame('200.00', $r->total, 'only nights 6 and 7 are in the stay window');
        Offer::acrossProperties()->update(['is_active' => false]);

        $this->offer(['booking_from' => $this->day(1)->toDateString()]);
        $this->assertSame('0.00', $this->evaluate([$this->stay($this->dlxBar, 5, ['1000'])])->total, 'booked before the booking window');
        $this->assertSame('100.00', $this->evaluate([$this->stay($this->dlxBar, 5, ['1000'])], null, ['booked_on' => $this->day(1)])->total);
        Offer::acrossProperties()->update(['is_active' => false]);

        // Weekend only (Sat 32 + Sun 64).
        $this->offer(['weekdays' => 96]);
        $prices = array_fill(0, 7, '1000');
        $this->assertSame('200.00', $this->evaluate([$this->stay($this->dlxBar, 3, $prices)])->total, 'one week has one Saturday and one Sunday');
        Offer::acrossProperties()->update(['is_active' => false]);

        $this->offer(['min_advance_days' => 30, 'offer_type' => 'early_bird']);
        $this->assertSame('0.00', $this->evaluate([$this->stay($this->dlxBar, 10, ['1000'])])->total);
        $this->assertSame('100.00', $this->evaluate([$this->stay($this->dlxBar, 30, ['1000'])])->total);
        Offer::acrossProperties()->update(['is_active' => false]);

        $this->offer(['max_advance_days' => 3, 'offer_type' => 'last_minute']);
        $this->assertSame('100.00', $this->evaluate([$this->stay($this->dlxBar, 2, ['1000'])])->total);
        $this->assertSame('0.00', $this->evaluate([$this->stay($this->dlxBar, 4, ['1000'])])->total);
    }

    public function test_stay_length_amount_scope_and_conditions(): void
    {
        $long = $this->offer(['min_nights' => 3, 'max_nights' => 5]);
        $this->assertSame('0.00', $this->evaluate([$this->stay($this->dlxBar, 5, ['1000', '1000'])])->total);
        $this->assertSame('300.00', $this->evaluate([$this->stay($this->dlxBar, 5, ['1000', '1000', '1000'])])->total);
        $this->assertSame('0.00', $this->evaluate([$this->stay($this->dlxBar, 5, array_fill(0, 6, '1000'))])->total);
        $long->update(['is_active' => false]);

        $this->offer(['min_amount' => '5000']);
        $this->assertSame('0.00', $this->evaluate([$this->stay($this->dlxBar, 5, ['1000'])])->total);
        $this->assertSame('600.00', $this->evaluate([$this->stay($this->dlxBar, 5, ['3000']), $this->stay($this->steBar, 5, ['3000'])])->total, 'booking total counts');
        Offer::acrossProperties()->update(['is_active' => false]);

        $this->offer([], [['room_type_id' => $this->suite->id, 'rate_plan_id' => null]]);
        $r = $this->evaluate([$this->stay($this->dlxBar, 5, ['1000']), $this->stay($this->steBar, 5, ['2000'])]);
        $this->assertSame('200.00', $r->total, 'only the suite');
        $this->assertSame('0.00', $r->nightDiscount(0, $this->day(5)->toDateString()));
        Offer::acrossProperties()->update(['is_active' => false]);

        $this->offer([], [], [
            ['condition_type' => 'min_adults', 'operator' => 'gte', 'value' => [3]],
        ]);
        $this->assertSame('0.00', $this->evaluate([$this->stay($this->dlxBar, 5, ['1000'])])->total);
        $this->assertSame('100.00', $this->evaluate([$this->stay($this->dlxBar, 5, ['1000'], ['adults' => 3])])->total);
        Offer::acrossProperties()->update(['is_active' => false]);

        $this->offer([], [], [
            ['condition_type' => 'source', 'operator' => 'in', 'value' => ['direct', 'corporate']],
            ['condition_type' => 'guest_country', 'operator' => 'not_in', 'value' => ['IN']],
        ]);
        $this->assertSame('0.00', $this->evaluate([$this->stay($this->dlxBar, 5, ['1000'])], null, ['source' => 'ota', 'country' => 'GB'])->total);
        $this->assertSame('0.00', $this->evaluate([$this->stay($this->dlxBar, 5, ['1000'])], null, ['source' => 'direct', 'country' => 'in'])->total);
        $this->assertSame('100.00', $this->evaluate([$this->stay($this->dlxBar, 5, ['1000'])], null, ['source' => 'direct', 'country' => 'GB'])->total);
    }

    public function test_channel_inactive_and_redemptions(): void
    {
        $this->offer(['on_booking_engine' => false]);
        $this->assertSame('100.00', $this->evaluate([$this->stay($this->dlxBar, 5, ['1000'])])->total);
        $this->assertSame('0.00', $this->evaluate([$this->stay($this->dlxBar, 5, ['1000'])], null, ['channel' => 'booking_engine'])->total);
        Offer::acrossProperties()->update(['is_active' => false]);

        $limited = $this->offer(['max_redemptions' => 1]);
        $this->assertSame('100.00', $this->evaluate([$this->stay($this->dlxBar, 5, ['1000'])])->total);
        app(\App\Domain\Offers\OfferService::class)->redeem([$limited->id]);
        $this->assertSame(1, (int) $limited->fresh()->redemptions);
        $this->assertSame('0.00', $this->evaluate([$this->stay($this->dlxBar, 5, ['1000'])])->total, 'used up');
        try {
            app(\App\Domain\Offers\OfferService::class)->redeem([$limited->id]);
            $this->fail('redeemed past the limit');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('promo_code', $e->errors());
        }
        app(\App\Domain\Offers\OfferService::class)->release([$limited->id]);
        $this->assertSame(0, (int) $limited->fresh()->redemptions);
    }

    public function test_priority_stacking_and_promo_codes(): void
    {
        $this->offer(['name' => 'Ten', 'discount_value' => '10', 'priority' => 1]);
        $this->offer(['name' => 'Twenty', 'discount_value' => '20', 'priority' => 0]);
        $r = $this->evaluate([$this->stay($this->dlxBar, 5, ['1000'])]);
        $this->assertSame(['Ten'], array_column($r->applied, 'name'), 'priority wins over size');
        Offer::acrossProperties()->update(['priority' => 0]);
        $this->assertSame(['Twenty'], array_column($this->evaluate([$this->stay($this->dlxBar, 5, ['1000'])])->applied, 'name'), 'same priority: bigger discount');

        Offer::acrossProperties()->update(['is_stackable' => true]);
        $r = $this->evaluate([$this->stay($this->dlxBar, 5, ['1000'])]);
        $this->assertSame('280.00', $r->total, '20 % of 1000, then 10 % of the remaining 800');
        $this->assertCount(2, $r->applied);
        Offer::acrossProperties()->update(['is_active' => false]);

        $promo = $this->offer(['name' => 'Promo', 'promo_code' => 'SUMMER', 'discount_value' => '15']);
        $auto = $this->offer(['name' => 'Auto', 'discount_value' => '25', 'priority' => 9]);
        $this->assertSame(['Auto'], array_column($this->evaluate([$this->stay($this->dlxBar, 5, ['1000'])])->applied, 'name'), 'promo offers need the code');
        $r = $this->evaluate([$this->stay($this->dlxBar, 5, ['1000'])], ' summer ');
        $this->assertSame(['Promo'], array_column($r->applied, 'name'), 'an entered valid code goes first');
        $this->assertSame('applied', $r->promo['status']);
        $this->assertSame('unknown', $this->evaluate([$this->stay($this->dlxBar, 5, ['1000'])], 'NOPE')->promo['status']);

        $promo->update(['min_nights' => 3]);
        $r = $this->evaluate([$this->stay($this->dlxBar, 5, ['1000'])], 'SUMMER');
        $this->assertSame('not_eligible', $r->promo['status']);
        $this->assertSame('offers.reasons.min_nights', $r->promo['reason']);
        $this->assertSame(['Auto'], array_column($r->applied, 'name'), 'automatic offers still apply');

        $promo->update(['min_nights' => null, 'is_active' => false]);
        $this->assertSame('offers.reasons.inactive', $this->evaluate([$this->stay($this->dlxBar, 5, ['1000'])], 'SUMMER')->promo['reason']);
        $auto->delete();
    }

    public function test_fixed_nights_are_never_discounted_and_other_properties_are_ignored(): void
    {
        $this->offer();
        [$other] = $this->createPropertyWithOwner();
        DB::table('offers')->insert([
            'public_id' => strtoupper(\Illuminate\Support\Str::ulid()), 'property_id' => $other->id, 'name' => 'Foreign', 'code' => 'F1',
            'discount_type' => 'percent', 'discount_value' => '90', 'weekdays' => 127, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $room = $this->stay($this->dlxBar, 5, ['1000', '1000'], ['fixed' => [$this->day(5)->toDateString()]]);
        $r = $this->evaluate([$room]);
        $this->assertSame('100.00', $r->total);
        $this->assertSame(['Offer'], array_column($r->applied, 'name'));
    }
}
