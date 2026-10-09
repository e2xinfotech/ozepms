<?php

namespace App\Domain\Channels\Providers;

use App\Domain\Channels\AriBatcher;
use App\Domain\Channels\Contracts\ChannelProvider;
use App\Domain\Channels\Contracts\ProvidesWebhookResponse;
use App\Domain\Channels\Data\AriUpdate;
use App\Domain\Channels\Data\InboundReservation;
use App\Domain\Channels\Data\ProviderResult;
use App\Models\ChannelConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * MakeMyTrip / Goibibo (Go-MMT) — PROVISIONAL, UNVERIFIED wire format.
 *
 * Go-MMT publishes no public connectivity specification. What is confirmed by vendor guides: the hotel copies a bearer
 * access token from its Go-MMT extranet (Property > channel manager) and the hotel code comes from the Go-MMT account manager;
 * rooms are mapped by the room ids shown in the extranet. Everything else here (JSON field names, paths, 30-day batches,
 * 5 requests per second, webhook answer) follows an unverified sample and MUST be replaced by the partner documentation
 * (channelmanager-tech@goibibo.com) before any hotel is connected. All of it is in this one class and in config/channels.php:
 * there is no default endpoint, so nothing is ever sent until api_url is set.
 */
class MakeMyTripProvider implements ChannelProvider, ProvidesWebhookResponse
{
    private const MAX_DAYS = 30;

    public function credentialFields(): array
    {
        return [['key' => 'access_token', 'type' => 'secret', 'required' => true]];
    }

    public function testConnection(ChannelConnection $connection): ProviderResult
    {
        if ($this->url('') === null) {
            return ProviderResult::fail(__('channels.makemytrip.not_configured'), false);
        }
        try {
            // No test call is documented; a one-day booking listing proves the token and the hotel code.
            $response = $this->post($connection, $this->bookingsPath(), ['hotel_code' => $connection->external_hotel_id, 'from_date' => gmdate('Y-m-d\T00:00:00\Z'), 'to_date' => gmdate('Y-m-d\T23:59:59\Z')], 30);
        } catch (ConnectionException) {
            return ProviderResult::fail(__('channels.makemytrip.unreachable'), true);
        }
        if (in_array($response->status(), [401, 403], true)) {
            return ProviderResult::fail(__('channels.makemytrip.bad_login'), false, null, $this->trim($response->body()));
        }

        return $response->successful()
            ? ProviderResult::ok(__('channels.makemytrip.connected', ['hotel' => $connection->external_hotel_id]), null, $this->trim($response->body()))
            : ProviderResult::fail('HTTP '.$response->status(), $response->status() >= 500, null, $this->trim($response->body()));
    }

    public function listings(ChannelConnection $connection): array
    {
        // No listing call is documented: rooms and rates are mapped with the ids shown in the Go-MMT extranet.
        return ['rooms' => [], 'rates' => []];
    }

    public function pushAri(ChannelConnection $connection, array $updates): ProviderResult
    {
        if ($this->url($this->ariPath()) === null) {
            return ProviderResult::fail(__('channels.makemytrip.not_configured'), false);
        }
        $currency = (string) (DB::table('properties')->where('id', $connection->property_id)->value('currency_code') ?? 'INR');
        $logRequest = [];
        $logResponse = [];
        // One calendar month per request (short payloads), every range cut to at most 30 days, up to 50 items per request;
        // at most 5 requests per second per hotel.
        $perRequest = max(1, (int) config('channels.providers.makemytrip.max_items_per_request', 50));
        $pause = (int) config('channels.providers.makemytrip.min_interval_ms', 200) * 1000;
        $batches = [];
        foreach (AriBatcher::months(AriBatcher::merge($updates)) as $segments) {
            $items = [];
            foreach (AriBatcher::windows($segments, self::MAX_DAYS) as $u) {
                /** @var AriUpdate $u */
                $item = ['room_type_id' => $u->roomId, 'rate_plan_id' => $u->rateId, 'dates' => ['start' => $u->from, 'end' => $u->to]];
                if ($u->availability !== null || ($u->rateId === null && $u->stopSell !== null)) {
                    $item['inventory'] = array_filter(['available_rooms' => $u->availability, 'stop_sell' => $u->stopSell === null ? null : (int) $u->stopSell], fn ($v) => $v !== null);
                }
                if ($u->price !== null) {
                    $item['pricing'] = ['currency' => $currency, 'occupancy_rates' => ['1' => $u->price, '2' => $u->price]];
                }
                $restrictions = array_filter([
                    'min_length_of_stay' => $u->minLos, 'max_length_of_stay' => $u->maxLos,
                    'close_to_arrival' => $u->cta === null ? null : (int) $u->cta, 'close_to_departure' => $u->ctd === null ? null : (int) $u->ctd,
                    'stop_sell' => $u->rateId !== null && $u->stopSell !== null ? (int) $u->stopSell : null,
                ], fn ($v) => $v !== null);
                if ($restrictions !== []) {
                    $item['restrictions'] = $restrictions;
                }
                $items[] = $item;
            }
            foreach (array_chunk($items, $perRequest) as $batch) {
                $batches[] = $batch;
            }
        }
        foreach ($batches as $i => $batch) {
            $payload = ['hotel_code' => $connection->external_hotel_id, 'update_type' => 'ARI', 'data' => $batch];
            $logRequest[] = json_encode($payload);
            try {
                $response = $this->post($connection, $this->ariPath(), $payload, 60);
            } catch (ConnectionException) {
                return ProviderResult::fail(__('channels.makemytrip.unreachable'), true, $this->trim(implode("\n", $logRequest)), $this->trim(implode("\n", $logResponse)));
            }
            $logResponse[] = $response->status().' '.$this->trim($response->body());
            $problem = $this->problem($response);
            if ($problem !== null) {
                return ProviderResult::fail($problem['message'], $problem['retryable'], $this->trim(implode("\n", $logRequest)), $this->trim(implode("\n", $logResponse)));
            }
            if ($pause > 0 && $i < count($batches) - 1) {
                usleep($pause);
            }
        }

        return ProviderResult::ok(__('channels.makemytrip.accepted', ['count' => count($updates)]), $this->trim(implode("\n", $logRequest)), $this->trim(implode("\n", $logResponse)));
    }

    public function parseWebhook(ChannelConnection $connection, Request $request): array
    {
        // The channel authenticates with the bearer token the hotel's extranet holds: our webhook secret.
        $secret = (string) (($connection->credentials ?? [])['webhook_secret'] ?? '');
        if ($secret === '' || ! hash_equals('Bearer '.$secret, (string) $request->header('Authorization', ''))) {
            abort(401, 'Unauthorized access token');
        }
        $data = (array) $request->json()->all();
        $items = isset($data['bookings']) ? (array) $data['bookings'] : [$data];

        return $this->translate(array_filter($items, 'is_array'));
    }

    public function webhookResponse(ChannelConnection $connection, array $results): array
    {
        $failed = array_values(array_filter($results, fn ($r) => $r['status'] === 'failed'));
        $first = $results[0] ?? ['external_ref' => ''];
        if ($failed !== []) {
            // A non-200 answer makes the channel send the booking again later.
            return [['status' => 'FAILED', 'booking_id' => $failed[0]['external_ref'], 'message' => (string) ($failed[0]['error'] ?? 'Processing error')], 500];
        }

        return [['status' => 'SUCCESS', 'booking_id' => $first['external_ref'], 'hotel_code' => $connection->external_hotel_id, 'ack_timestamp' => gmdate('c'), 'message' => 'Booking processed successfully'], 200];
    }

    public function pullReservations(ChannelConnection $connection): array
    {
        if ($this->url('') === null) {
            return [];
        }
        $from = (int) $connection->setting('makemytrip_last_poll', time() - 2 * 86400) - 900;
        $to = time();
        try {
            $response = $this->post($connection, $this->bookingsPath(), ['hotel_code' => $connection->external_hotel_id, 'from_date' => gmdate('Y-m-d\TH:i:s\Z', $from), 'to_date' => gmdate('Y-m-d\TH:i:s\Z', $to)], 120);
        } catch (ConnectionException) {
            return [];
        }
        if (! $response->successful()) {
            return [];
        }
        $connection->forceFill(['settings' => array_merge($connection->settings ?? [], ['makemytrip_last_poll' => $to])])->saveQuietly();
        $list = (array) ($response->json('bookings') ?? $response->json('data') ?? []);

        return $this->translate(array_filter($list, 'is_array'));
    }

    public function acknowledge(ChannelConnection $connection, InboundReservation $booking, bool $ok, ?string $pmsRef, ?string $error): void
    {
        // Answered in the webhook response; polled bookings need no confirmation.
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return list<InboundReservation>
     */
    public function translate(array $items): array
    {
        $out = [];
        foreach ($items as $b) {
            $ref = (string) ($b['booking_id'] ?? '');
            $status = strtoupper((string) ($b['status'] ?? 'CONFIRMED'));
            $type = str_contains($status, 'CANCEL') ? 'cancel' : (str_contains($status, 'MODIF') ? 'modify' : 'new');
            $stay = (array) ($b['stay_details'] ?? []);
            $guest = (array) ($b['guest_details'] ?? []);
            $pay = (array) ($b['payment_summary'] ?? []);
            $in = substr((string) ($stay['check_in'] ?? ''), 0, 10);
            $outDate = substr((string) ($stay['check_out'] ?? ''), 0, 10);
            $nights = $in !== '' && $outDate !== '' ? max(1, (int) round((strtotime($outDate) - strtotime($in)) / 86400)) : 1;
            $total = (float) ($pay['total_price'] ?? 0);
            $nightly = [];
            for ($d = strtotime($in); $in !== '' && $d < strtotime($outDate); $d += 86400) {
                $nightly[date('Y-m-d', $d)] = number_format($total / $nights, 2, '.', '');
            }
            $version = $type === 'new' ? 1 : max(2, (int) strtotime((string) ($b['modified_at'] ?? $b['updated_at'] ?? 'now')));
            $out[] = new InboundReservation($type, $ref, $version, [
                'first_name' => (string) ($guest['first_name'] ?? ''), 'last_name' => (string) ($guest['last_name'] ?? '') ?: 'Guest',
                'email' => ($guest['email'] ?? null) ?: null, 'phone' => ($guest['phone'] ?? null) ?: null, 'country' => null,
            ], [[
                'room_id' => (string) ($stay['room_type_id'] ?? ''), 'rate_id' => ($stay['rate_plan_id'] ?? null) ? (string) $stay['rate_plan_id'] : null,
                'check_in' => $in, 'check_out' => $outDate, 'adults' => max(1, (int) ($stay['adults'] ?? 2)), 'children' => (int) ($stay['children'] ?? 0), 'infants' => 0, 'nightly' => $nightly,
            ]], isset($pay['currency']) ? strtoupper((string) $pay['currency']) : 'INR', $total > 0 ? number_format($total, 2, '.', '') : null, null, ['source' => strtolower((string) ($b['channel'] ?? 'makemytrip'))]);
        }

        return $out;
    }

    // ---------------------------------------------------------------- helpers

    private function post(ChannelConnection $c, string $path, array $payload, int $timeout): Response
    {
        return Http::timeout($timeout)->withToken((string) (($c->credentials ?? [])['access_token'] ?? ''))->acceptJson()->asJson()->post((string) $this->url($path), $payload);
    }

    /** Null while no api_url is configured (nothing may be sent then). */
    private function url(string $path): ?string
    {
        $base = (string) config('channels.providers.makemytrip.api_url', '');

        return $base === '' ? null : rtrim($base, '/').$path;
    }

    private function ariPath(): string
    {
        return (string) config('channels.providers.makemytrip.ari_path', '/ari');
    }

    private function bookingsPath(): string
    {
        return (string) config('channels.providers.makemytrip.bookings_path', '/api/chmv2/getbookinglisting');
    }

    /** @return array{message: string, retryable: bool}|null */
    private function problem(Response $response): ?array
    {
        $status = strtoupper((string) ($response->json('status') ?? ''));
        if ($response->successful() && ! in_array($status, ['FAILED', 'ERROR', 'FAILURE'], true)) {
            return null;
        }
        $message = (string) ($response->json('message') ?? $response->json('error') ?? 'HTTP '.$response->status());

        return ['message' => $message, 'retryable' => $response->status() >= 500 || in_array($response->status(), [408, 429], true)];
    }

    private function trim(string $t): string
    {
        return mb_substr($t, 0, 20000);
    }
}
