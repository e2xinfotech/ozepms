<?php

namespace Tests\Feature\Offers;

use App\Domain\Offers\OfferContext;
use App\Domain\Offers\OfferResult;
use App\Domain\Offers\OfferService;
use App\Models\Offer;
use App\Models\Product;
use Illuminate\Support\Str;
use Tests\Feature\Reservations\ReservationTestCase;

abstract class OfferTestCase extends ReservationTestCase
{
    protected function offer(array $data = [], array $scopes = [], array $conditions = []): Offer
    {
        $offer = Offer::acrossProperties()->create(array_merge([
            'property_id' => $this->property->id, 'name' => 'Offer', 'code' => 'O'.Str::upper(Str::random(6)),
            'discount_type' => 'percent', 'discount_value' => '10', 'weekdays' => Offer::ALL_DAYS, 'priority' => 0,
            'is_stackable' => false, 'on_pms' => true, 'on_booking_engine' => true, 'is_active' => true,
        ], $data));
        foreach ($scopes as $s) {
            $offer->scopes()->create($s);
        }
        foreach ($conditions as $c) {
            $offer->conditions()->create($c);
        }

        return $offer->fresh();
    }

    /** Offer-engine room: $nights prices from day $in on (one price per night). */
    protected function stay(Product $product, int $in, array $prices, array $extra = []): array
    {
        $nights = [];
        foreach (array_values($prices) as $i => $p) {
            $nights[$this->day($in + $i)->toDateString()] = $p;
        }

        return array_merge([
            'room_type_id' => $product->room_type_id, 'rate_plan_id' => $product->rate_plan_id,
            'check_in' => $this->day($in), 'check_out' => $this->day($in + count($prices)), 'adults' => 2, 'nights' => $nights,
        ], $extra);
    }

    protected function evaluate(array $rooms, ?string $promo = null, array $ctx = []): OfferResult
    {
        return app(OfferService::class)->evaluate($this->property->fresh(), new OfferContext(
            $ctx['booked_on'] ?? $this->day(0), $ctx['channel'] ?? 'pms', $promo, $ctx['source'] ?? null, $ctx['country'] ?? null,
        ), $rooms);
    }
}
