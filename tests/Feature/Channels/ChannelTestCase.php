<?php

namespace Tests\Feature\Channels;

use App\Domain\Channels\ChannelReservationService;
use App\Domain\Channels\ChannelSyncService;
use App\Domain\Channels\ConnectionService;
use App\Models\ChannelConnection;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Reservations\ReservationTestCase;

/** Main property on a plan with the channel manager; DLX (3 rooms) and STE (1 room) on BAR. */
abstract class ChannelTestCase extends ReservationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pro = SubscriptionPlan::query()->where('code', 'professional')->firstOrFail();
        DB::table('subscriptions')->where('property_id', $this->property->id)->update(['plan_id' => $pro->id]);
        Cache::flush();
    }

    protected function connections(): ConnectionService
    {
        return app(ConnectionService::class);
    }

    protected function syncer(): ChannelSyncService
    {
        return app(ChannelSyncService::class);
    }

    protected function inbound(): ChannelReservationService
    {
        return app(ChannelReservationService::class);
    }

    /** Test Channel connected and mapped (DLX + STE rooms, both BAR rates; DLX rate +10 %). */
    protected function connected(): ChannelConnection
    {
        $c = $this->connections()->create($this->property, ['provider' => 'test', 'external_hotel_id' => 'H-100', 'credentials' => ['api_key' => 'k-123']], $this->owner);
        $this->connections()->saveMappings($c, [$this->deluxe->public_id => 'TEST-DLX', $this->suite->public_id => 'TEST-STE'], [
            ['product_id' => $this->dlxBar->id, 'external_rate_id' => 'TEST-DLX-BAR', 'markup_type' => 'percent', 'markup_value' => '10'],
            ['product_id' => $this->steBar->id, 'external_rate_id' => 'TEST-STE-BAR', 'markup_type' => 'none', 'markup_value' => null],
        ], $this->owner);

        return $c->fresh();
    }

    /** What the Test Channel holds for a room (rate = null) or rate on day $offset. */
    protected function held(ChannelConnection $c, string $room, ?string $rate, int $offset): ?object
    {
        return DB::table('channel_test_listings')->where('connection_id', $c->id)->where('external_room_id', $room)
            ->where('external_rate_id', (string) ($rate ?? ''))->where('stay_date', $this->day($offset)->toDateString())->first();
    }

    protected function booking(string $ref, int $in, int $out, array $extra = []): array
    {
        $nightly = [];
        for ($d = $in; $d < $out; $d++) {
            $nightly[$this->day($d)->toDateString()] = '3500.00';
        }

        return array_merge([
            'type' => 'new', 'external_ref' => $ref, 'version' => 1, 'currency' => 'INR',
            'guest' => ['first_name' => 'Anna', 'last_name' => 'Keller', 'email' => 'anna.keller@example.com', 'country' => 'DE'],
            'rooms' => [['room_id' => 'TEST-DLX', 'rate_id' => 'TEST-DLX-BAR', 'check_in' => $this->day($in)->toDateString(), 'check_out' => $this->day($out)->toDateString(), 'adults' => 2, 'nightly' => $nightly]],
        ], $extra);
    }
}
