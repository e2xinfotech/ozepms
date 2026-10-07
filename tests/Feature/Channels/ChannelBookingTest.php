<?php

namespace Tests\Feature\Channels;

use App\Domain\Channels\Data\InboundReservation;
use App\Models\ChannelReservation;
use App\Models\Reservation;
use Illuminate\Support\Facades\DB;

/** Bookings, changes and cancellations from a channel; webhook. */
class ChannelBookingTest extends ChannelTestCase
{
    public function test_new_booking_is_imported_once_with_channel_prices(): void
    {
        $c = $this->connected();
        $r = $this->inbound()->ingest($c, InboundReservation::fromArray($this->booking('OTA-1001', 4, 6)));
        $this->assertSame('new', $r['status']);
        $res = Reservation::acrossProperties()->findOrFail($r['reservation']->id);
        $this->assertSame('confirmed', $res->status);
        $this->assertSame('7000.00', (string) $res->room_total, 'channel price per night, not the PMS rate');
        $this->assertSame('ota', DB::table('booking_sources')->where('id', $res->source_id)->value('code'));
        $this->assertStringContainsString('OTA-1001', (string) $res->channel_ref);
        $this->assertSame([1, 1], $this->sold($this->deluxe, 4, 6));

        $again = $this->inbound()->ingest($c, InboundReservation::fromArray($this->booking('OTA-1001', 4, 6)));
        $this->assertSame('duplicate', $again['status']);
        $this->assertSame(1, Reservation::acrossProperties()->where('property_id', $this->property->id)->count());
        $this->assertSame('new', ChannelReservation::query()->where('external_ref', 'OTA-1001')->value('status'));

        // The booking is sent back to every channel as lower availability.
        $this->syncer()->syncDue();
        $this->assertSame(2, (int) $this->held($c, 'TEST-DLX', null, 4)->availability);
    }

    public function test_modification_and_cancellation(): void
    {
        $c = $this->connected();
        $res = $this->inbound()->ingest($c, InboundReservation::fromArray($this->booking('OTA-2', 4, 6)))['reservation'];

        $m = $this->inbound()->ingest($c, InboundReservation::fromArray($this->booking('OTA-2', 5, 8, ['type' => 'modify', 'version' => 2])));
        $this->assertSame('modified', $m['status']);
        $res = $res->fresh();
        $this->assertSame($this->day(5)->toDateString(), $res->check_in->toDateString());
        $this->assertSame(3, (int) $res->nights);
        $this->assertSame([0, 1, 1, 1], $this->sold($this->deluxe, 4, 8));

        $old = $this->inbound()->ingest($c, InboundReservation::fromArray($this->booking('OTA-2', 4, 6, ['type' => 'modify', 'version' => 1])));
        $this->assertSame('duplicate', $old['status'], 'an older version is ignored');

        $x = $this->inbound()->ingest($c, InboundReservation::fromArray(['type' => 'cancel', 'external_ref' => 'OTA-2', 'version' => 3]));
        $this->assertSame('cancelled', $x['status']);
        $this->assertSame('cancelled', $res->fresh()->status);
        $this->assertSame('0.00', (string) $res->fresh()->cancellation_fee, 'the channel handles its own fees');
        $this->assertSame([0, 0, 0], $this->sold($this->deluxe, 5, 8));
    }

    public function test_failed_imports_are_kept_and_can_be_retried(): void
    {
        $c = $this->connected();
        $bad = $this->inbound()->ingest($c, InboundReservation::fromArray($this->booking('OTA-3', 2, 3, ['rooms' => [['room_id' => 'NOPE', 'check_in' => $this->day(2)->toDateString(), 'check_out' => $this->day(3)->toDateString(), 'adults' => 2, 'price' => '100']]])));
        $this->assertSame('failed', $bad['status']);
        $this->assertStringContainsString('NOPE', ChannelReservation::query()->where('external_ref', 'OTA-3')->value('error'));

        // Overbooking: the suite (1 room) is already sold by the front desk.
        $pms = $this->book([$this->room($this->steBar, 7, 8)]);
        $over = $this->booking('OTA-4', 7, 8, ['rooms' => [['room_id' => 'TEST-STE', 'rate_id' => 'TEST-STE-BAR', 'check_in' => $this->day(7)->toDateString(), 'check_out' => $this->day(8)->toDateString(), 'adults' => 2, 'price' => '9000']]]);
        $this->assertSame('failed', $this->inbound()->ingest($c, InboundReservation::fromArray($over))['status']);
        $record = ChannelReservation::query()->where('external_ref', 'OTA-4')->firstOrFail();
        $this->assertSame('failed', $record->status);

        $this->inProperty($this->property);
        $this->service()->cancel($pms, 'Moved', $this->owner);
        $retry = $this->inbound()->retry($record);
        $this->assertSame('new', $retry['status']);
        $this->assertNull($record->fresh()->error);

        $wrong = $this->inbound()->ingest($c, InboundReservation::fromArray($this->booking('OTA-5', 9, 10, ['currency' => 'EUR'])));
        $this->assertSame('failed', $wrong['status']);
        $this->assertSame('failed', $this->inbound()->ingest($c, InboundReservation::fromArray(['type' => 'cancel', 'external_ref' => 'NOT-THERE']))['status']);
    }

    public function test_restrictions_do_not_block_channel_bookings(): void
    {
        $c = $this->connected();
        $this->apply(['date_from' => $this->day(3)->toDateString(), 'date_to' => $this->day(3)->toDateString(), 'product_ids' => [$this->dlxBar->id], 'min_los' => 5]);
        $r = $this->inbound()->ingest($c, InboundReservation::fromArray($this->booking('OTA-6', 3, 4)));
        $this->assertSame('new', $r['status'], 'the channel sold it; min stay is not checked again');
    }

    public function test_webhook_verifies_signature_and_connection_state(): void
    {
        $c = $this->connected();
        $body = json_encode(['bookings' => [$this->booking('WH-1', 4, 5)]]);
        $sig = hash_hmac('sha256', $body, $c->credentials['webhook_secret']);
        $url = '/hooks/channels/test/'.$c->public_id;

        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CHANNEL_SIGNATURE' => 'bad'], $body)->assertStatus(401);
        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CHANNEL_SIGNATURE' => $sig], $body)
            ->assertOk()->assertJsonPath('results.0.status', 'new');
        $this->call('POST', '/hooks/channels/test/01ARZ3NDEKTSV4RRFFQ69G5FAV', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertNotFound();

        $this->connections()->pause($c, $this->owner);
        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CHANNEL_SIGNATURE' => $sig], $body)->assertStatus(409);
    }
}
