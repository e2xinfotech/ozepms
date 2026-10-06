<?php

namespace Database\Seeders;

use App\Domain\Accommodation\InProperty;
use App\Domain\Offers\OfferAdminService;
use App\Models\Offer;
use App\Models\Property;
use App\Models\RoomType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Demo offers for the demo properties, like the approved design (offers.png): room discounts,
 * packages (incl. stay 3 pay 2), early bird, last minute, weekend, long stay, a promo code and a
 * scheduled festive offer. Dates are relative to today so every status tab has rows. Idempotent by name.
 */
class OfferDemoSeeder extends Seeder
{
    public function run(): void
    {
        foreach (AccommodationDemoSeeder::demoProperties() as $property) {
            $count = InProperty::run($property, fn () => $this->seedProperty($property));
            $this->command?->info("Offers ready for {$property->code} ({$count} offers).");
        }
    }

    private function seedProperty(Property $property): int
    {
        $service = app(OfferAdminService::class);
        $today = CarbonImmutable::now($property->timezone ?: config('app.timezone'))->startOfDay();
        $inr = $property->currency_code === 'INR';
        $suite = RoomType::query()->where('is_active', true)->orderByDesc('max_occupancy')->first();
        $d = fn (CarbonImmutable $x) => $x->toDateString();
        $year = $today->year;

        $offers = [
            ['name' => 'Summer Getaway', 'offer_type' => 'room_discount', 'discount_type' => 'percent', 'discount_value' => '20',
                'stay_from' => "$year-06-01", 'stay_to' => "$year-08-31", 'booking_from' => "$year-05-01", 'booking_to' => "$year-08-31",
                'description' => "Enjoy a relaxing summer vacation with 20% off our best available rates."],
            ['name' => 'Early Bird Offer', 'offer_type' => 'early_bird', 'discount_type' => 'percent', 'discount_value' => '15', 'min_advance_days' => 30, 'priority' => 5,
                'description' => 'Plan ahead and save: book at least 30 days before arrival.'],
            ['name' => 'Last Minute Deal', 'offer_type' => 'last_minute', 'discount_type' => 'percent', 'discount_value' => '25', 'max_advance_days' => 3, 'priority' => 5,
                'on_booking_engine' => true, 'sources' => ['direct', 'booking_engine'], 'description' => 'Spontaneous trip? Save 25% when you book up to 3 days before arrival.'],
            ['name' => 'Weekend Special', 'offer_type' => 'room_discount', 'discount_type' => 'percent', 'discount_value' => '10', 'weekdays' => 16 + 32 + 64, 'priority' => 2,
                'description' => 'Friday to Sunday nights at 10% off.'],
            ['name' => 'Honeymoon Package', 'offer_type' => 'package', 'discount_type' => 'fixed_per_night', 'discount_value' => $inr ? '2500' : '500', 'min_nights' => 2,
                'promo_code' => 'HONEYMOON', 'room_types' => $suite ? [$suite] : [], 'priority' => 3,
                'stay_from' => $d($today->startOfYear()), 'stay_to' => $d($today->endOfYear()), 'description' => 'Romantic escape for couples, with a special nightly rate.'],
            ['name' => 'Stay 3 Pay 2', 'offer_type' => 'package', 'discount_type' => 'free_nights', 'discount_value' => '1', 'min_nights' => 3, 'priority' => 4,
                'stay_from' => $d($today), 'stay_to' => $d($today->addMonths(6)), 'description' => 'Stay three nights and pay for two — the cheapest night is on us.'],
            ['name' => 'Business Traveler', 'offer_type' => 'room_discount', 'discount_type' => 'percent', 'discount_value' => '18', 'sources' => ['corporate'],
                'stay_from' => $d($today->startOfYear()), 'stay_to' => $d($today->endOfYear()), 'description' => 'Corporate rate for business guests.'],
            ['name' => 'Long Stay Offer', 'offer_type' => 'long_stay', 'discount_type' => 'percent', 'discount_value' => '30', 'min_nights' => 7, 'priority' => 4,
                'stay_from' => $d($today->startOfYear()), 'stay_to' => $d($today->subDay()), 'description' => 'Stay a week or longer and save 30%.'],
            ['name' => 'Family Package', 'offer_type' => 'package', 'discount_type' => 'fixed_per_night', 'discount_value' => $inr ? '2000' : '400', 'min_adults' => 3,
                'stay_from' => $d($today->subMonths(6)), 'stay_to' => $d($today->addMonths(6)), 'description' => 'More room for the whole family.'],
            ['name' => 'Festive Offer', 'offer_type' => 'room_discount', 'discount_type' => 'percent', 'discount_value' => '25',
                'booking_from' => "$year-12-01", 'stay_from' => "$year-12-15", 'stay_to' => ($year + 1).'-01-10', 'description' => 'Celebrate the festive season with us.'],
        ];

        $made = 0;
        foreach ($offers as $data) {
            if (Offer::query()->where('name', $data['name'])->exists()) {
                continue;
            }
            $service->create($data + ['weekdays' => Offer::ALL_DAYS, 'on_pms' => true, 'on_booking_engine' => true, 'is_active' => true]);
            $made++;
        }

        return Offer::query()->count();
    }
}
