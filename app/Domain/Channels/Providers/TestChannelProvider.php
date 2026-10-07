<?php

namespace App\Domain\Channels\Providers;

use App\Domain\Channels\Contracts\ChannelProvider;
use App\Domain\Channels\Data\InboundReservation;
use App\Domain\Channels\Data\ProviderResult;
use App\Models\ChannelConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Built-in Test Channel: behaves like an OTA so every channel manager flow can be used before a
 * real OTA is connected. It keeps what it receives in channel_test_listings (its "extranet"),
 * accepts signed webhook bookings like a real channel, and can simulate outages
 * (settings.simulate_failures = number of next pushes that fail) to show retries.
 * Its rooms and rates mirror the property's room types and sellable products.
 */
class TestChannelProvider implements ChannelProvider
{
    public function credentialFields(): array
    {
        return [['key' => 'api_key', 'type' => 'secret', 'required' => true]];
    }

    public function testConnection(ChannelConnection $connection): ProviderResult
    {
        $key = (string) (($connection->credentials ?? [])['api_key'] ?? '');
        if ($key === '' || strtolower($key) === 'invalid') {
            return ProviderResult::fail(__('channels.test_channel.invalid_key'), false);
        }

        return ProviderResult::ok(__('channels.test_channel.connected', ['hotel' => $connection->external_hotel_id]));
    }

    public function listings(ChannelConnection $connection): array
    {
        $rooms = DB::table('room_types')->where('property_id', $connection->property_id)->whereNull('deleted_at')
            ->orderBy('sort_order')->orderBy('name')->get(['id', 'code', 'name']);
        $rates = DB::table('room_type_rate_plans as p')->join('rate_plans as rp', 'rp.id', '=', 'p.rate_plan_id')->join('room_types as rt', 'rt.id', '=', 'p.room_type_id')
            ->where('p.property_id', $connection->property_id)->whereNull('rt.deleted_at')->where('rp.sell_on_channels', 1)
            ->orderBy('rt.sort_order')->orderBy('rp.sort_order')->get(['rt.code as rt_code', 'rp.code as rp_code', 'rp.name as rp_name', 'rt.name as rt_name']);

        return [
            'rooms' => $rooms->map(fn ($r) => ['id' => 'TEST-'.$r->code, 'name' => $r->name])->values()->all(),
            'rates' => $rates->map(fn ($r) => ['id' => 'TEST-'.$r->rt_code.'-'.$r->rp_code, 'name' => $r->rt_name.' — '.$r->rp_name, 'room_id' => 'TEST-'.$r->rt_code])->values()->all(),
        ];
    }

    public function pushAri(ChannelConnection $connection, array $updates): ProviderResult
    {
        $request = json_encode(['hotel_id' => $connection->external_hotel_id, 'updates' => array_map(fn ($u) => $u->toArray(), $updates)], JSON_UNESCAPED_SLASHES);
        $failures = (int) $connection->setting('simulate_failures', 0);
        if ($failures > 0) {
            $connection->forceFill(['settings' => array_merge($connection->settings ?? [], ['simulate_failures' => $failures - 1])])->saveQuietly();

            return ProviderResult::fail(__('channels.test_channel.unavailable'), true, $request, '{"error":"503 Service Unavailable"}');
        }

        $now = now()->format('Y-m-d H:i:s');
        $rows = [];
        foreach ($updates as $u) {
            for ($d = strtotime($u->from); $d <= strtotime($u->to); $d += 86400) {
                $rows[] = [
                    'connection_id' => $connection->id, 'external_room_id' => $u->roomId, 'external_rate_id' => (string) ($u->rateId ?? ''),
                    'stay_date' => date('Y-m-d', $d), 'availability' => $u->availability, 'price' => $u->price, 'min_los' => $u->minLos,
                    'max_los' => $u->maxLos, 'cta' => (int) ($u->cta ?? false), 'ctd' => (int) ($u->ctd ?? false), 'stop_sell' => (int) ($u->stopSell ?? false), 'updated_at' => $now,
                ];
            }
        }
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('channel_test_listings')->upsert($chunk, ['connection_id', 'external_room_id', 'external_rate_id', 'stay_date'],
                ['availability', 'price', 'min_los', 'max_los', 'cta', 'ctd', 'stop_sell', 'updated_at']);
        }

        return ProviderResult::ok(__('channels.test_channel.accepted', ['count' => count($updates)]), $request, json_encode(['status' => 'ok', 'accepted' => count($updates), 'nights' => count($rows)]));
    }

    public function parseWebhook(ChannelConnection $connection, Request $request): array
    {
        $secret = (string) (($connection->credentials ?? [])['webhook_secret'] ?? '');
        $signature = (string) $request->header('X-Channel-Signature', '');
        if ($secret === '' || ! hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $signature)) {
            abort(401, 'Invalid signature');
        }
        $data = json_decode($request->getContent(), true);
        $items = isset($data['bookings']) ? (array) $data['bookings'] : [$data];

        return array_map(fn ($b) => InboundReservation::fromArray((array) $b), array_filter($items, 'is_array'));
    }

    public function pullReservations(ChannelConnection $connection): array
    {
        return [];
    }

    public function acknowledge(ChannelConnection $connection, InboundReservation $booking, bool $ok, ?string $pmsRef, ?string $error): void
    {
        // The Test Channel needs no confirmation.
    }
}
