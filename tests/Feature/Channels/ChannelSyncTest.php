<?php

namespace Tests\Feature\Channels;

use App\Models\ChannelConnection;
use App\Models\ChannelSyncLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Connections, mapping, full and delta sync, retries. */
class ChannelSyncTest extends ChannelTestCase
{
    public function test_connection_is_tested_and_credentials_are_encrypted(): void
    {
        $c = $this->connections()->create($this->property, ['provider' => 'test', 'external_hotel_id' => 'H-1', 'credentials' => ['api_key' => 'secret-key-1']], $this->owner);
        $this->assertSame('active', $c->status);
        $raw = DB::table('channel_connections')->where('id', $c->id)->value('credentials');
        $this->assertStringNotContainsString('secret-key-1', (string) $raw);
        $this->assertSame('secret-key-1', $c->credentials['api_key']);
        $this->assertNotEmpty($c->credentials['webhook_secret']);
        $this->assertNotEmpty($c->public_id);

        try {
            $this->connections()->create($this->property, ['provider' => 'test', 'external_hotel_id' => 'H-2', 'credentials' => ['api_key' => 'x']], $this->owner);
            $this->fail('second Test Channel');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('provider', $e->errors());
        }
        $this->expectException(ValidationException::class);
        $this->connections()->create($this->property, ['provider' => 'booking_com', 'external_hotel_id' => 'B-1', 'credentials' => []], $this->owner);
    }

    public function test_invalid_key_stays_pending_and_edit_keeps_secret(): void
    {
        $c = $this->connections()->create($this->other, ['provider' => 'test', 'external_hotel_id' => 'H-9', 'credentials' => ['api_key' => 'invalid']], $this->otherOwner);
        $this->assertSame('pending', $c->status);
        $this->assertNotNull($c->last_error);
        $c = $this->connections()->update($c, ['name' => 'Practice', 'external_hotel_id' => 'H-9', 'credentials' => ['api_key' => 'good']], $this->otherOwner);
        $this->assertSame('active', $c->status);
        $c = $this->connections()->update($c, ['name' => 'Practice 2', 'credentials' => ['api_key' => '']], $this->otherOwner);
        $this->assertSame('good', $c->credentials['api_key'], 'empty secret keeps the saved one');
    }

    public function test_full_sync_after_mapping_sends_availability_prices_and_markup(): void
    {
        $c = $this->connected();
        $suggest = $this->connections()->suggestions($c);
        $this->assertSame('TEST-DLX', $suggest['rooms'][$this->deluxe->public_id]);
        $this->assertSame('TEST-DLX-BAR', $suggest['rates'][$this->dlxBar->id]);

        $this->assertSame(3, (int) $this->held($c, 'TEST-DLX', null, 5)->availability);
        $this->assertSame(1, (int) $this->held($c, 'TEST-STE', null, 5)->availability);
        $this->assertSame('4400.00', $this->held($c, 'TEST-DLX', 'TEST-DLX-BAR', 5)->price, '4000 + 10 % markup');
        $this->assertSame('9000.00', $this->held($c, 'TEST-STE', 'TEST-STE-BAR', 5)->price);
        $this->assertSame(DB::table('inventory_daily')->where('room_type_id', $this->deluxe->id)->where('stay_date', '>=', $this->day(0)->toDateString())->count(), DB::table('channel_test_listings')->where('connection_id', $c->id)->where('external_room_id', 'TEST-DLX')->where('external_rate_id', '')->count(), 'every night with inventory');
        $this->assertNotNull($c->last_full_sync_at);
        $this->assertSame((int) DB::table('ari_change_log')->where('property_id', $this->property->id)->max('id'), $c->last_ari_log_id);
        $this->assertTrue(ChannelSyncLog::query()->where('connection_id', $c->id)->where('message_type', 'full_sync')->where('status', 'success')->exists());
    }

    public function test_delta_sends_booking_and_calendar_changes_only(): void
    {
        $c = $this->connected();
        $before = ChannelSyncLog::query()->where('connection_id', $c->id)->count();
        $this->book([$this->room($this->dlxBar, 3, 5)]);
        $this->apply(['date_from' => $this->day(10)->toDateString(), 'date_to' => $this->day(10)->toDateString(), 'product_ids' => [$this->dlxBar->id], 'price' => '5000']);
        $this->apply(['date_from' => $this->day(12)->toDateString(), 'date_to' => $this->day(13)->toDateString(), 'product_ids' => [$this->steBar->id], 'min_los' => 2]);

        $this->assertSame(1, $this->syncer()->syncDue());
        $this->assertSame([2, 2, 3], [(int) $this->held($c, 'TEST-DLX', null, 3)->availability, (int) $this->held($c, 'TEST-DLX', null, 4)->availability, (int) $this->held($c, 'TEST-DLX', null, 5)->availability]);
        $this->assertSame('5500.00', $this->held($c, 'TEST-DLX', 'TEST-DLX-BAR', 10)->price);
        $this->assertSame(2, (int) $this->held($c, 'TEST-STE', 'TEST-STE-BAR', 12)->min_los);
        $log = ChannelSyncLog::query()->where('connection_id', $c->id)->where('message_type', 'ari_update')->latest('id')->first();
        $this->assertSame('success', $log->status);
        $this->assertLessThan(20, $log->items, 'only changed nights, merged into runs');
        $this->assertSame(0, $this->syncer()->syncDue(), 'nothing pending any more');
        $this->assertSame($before + 1, ChannelSyncLog::query()->where('connection_id', $c->id)->count());
    }

    public function test_failures_back_off_keep_the_mark_and_recover(): void
    {
        config(['channels.error_after_failures' => 2]);
        $c = $this->connected();
        $c->forceFill(['settings' => ['simulate_failures' => 2]])->save();
        $mark = $c->last_ari_log_id;
        $this->book([$this->room($this->steBar, 2, 3)]);

        $this->syncer()->syncDue();
        $c->refresh();
        $this->assertSame(1, $c->failures);
        $this->assertSame($mark, $c->last_ari_log_id, 'mark kept after a failure');
        $this->assertTrue($c->next_attempt_at->isFuture());
        $this->assertSame(0, $this->syncer()->syncDue(), 'waiting for the back-off');
        $this->assertSame(1, (int) $this->held($c, 'TEST-STE', null, 2)->availability, 'channel still has the old value');

        $this->travel(2)->minutes();
        $this->syncer()->syncDue();
        $c->refresh();
        $this->assertSame('error', $c->status);
        $this->assertSame(2, $c->failures);

        $this->travel(5)->minutes();
        $this->syncer()->syncDue();
        $c->refresh();
        $this->assertSame('active', $c->status);
        $this->assertSame(0, $c->failures);
        $this->assertNull($c->last_error);
        $this->assertSame(0, (int) $this->held($c, 'TEST-STE', null, 2)->availability);
        $this->assertSame(2, ChannelSyncLog::query()->where('connection_id', $c->id)->where('status', 'retrying')->count());
    }

    public function test_paused_connections_are_not_synced_and_resume_catches_up(): void
    {
        $c = $this->connected();
        $this->connections()->pause($c, $this->owner);
        $this->book([$this->room($this->dlxBar, 1, 2)]);
        $this->assertSame(0, $this->syncer()->syncDue());
        $this->assertSame(3, (int) $this->held($c, 'TEST-DLX', null, 1)->availability);
        $this->connections()->resume($c->fresh(), $this->owner);
        $this->assertSame(2, (int) $this->held($c, 'TEST-DLX', null, 1)->availability);
        $this->assertDatabaseHas('audit_logs', ['action' => 'channel.paused']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'channel.resumed']);
        $this->assertSame(ChannelConnection::class, get_class($c));
    }
}
