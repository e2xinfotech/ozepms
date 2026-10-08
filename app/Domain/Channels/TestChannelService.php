<?php

namespace App\Domain\Channels;

use App\Domain\Channels\Data\InboundReservation;
use App\Models\ChannelConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Practice tools of the Test Channel: what it holds (its "extranet"), bookings that arrive as signed
 * messages exactly like a real channel's webhook, and a simulated outage to show the retries.
 */
class TestChannelService
{
    public function __construct(private readonly ChannelRegistry $registry, private readonly ChannelReservationService $inbound) {}

    /** Rows the Test Channel received, grouped by room and rate, for the next $days days. */
    public function holds(ChannelConnection $c, int $days = 14): array
    {
        $this->assertTest($c);
        $from = now()->toDateString();
        $to = now()->addDays($days - 1)->toDateString();
        $rows = DB::table('channel_test_listings')->where('connection_id', $c->id)->whereBetween('stay_date', [$from, $to])
            ->orderBy('external_room_id')->orderBy('external_rate_id')->orderBy('stay_date')->get();

        return $rows->groupBy(fn ($r) => $r->external_room_id.'|'.$r->external_rate_id)->map(fn ($g) => [
            'room_id' => $g->first()->external_room_id, 'rate_id' => $g->first()->external_rate_id ?: null,
            'days' => $g->map(fn ($r) => [
                'date' => $r->stay_date, 'availability' => $r->availability, 'price' => $r->price, 'min_los' => $r->min_los, 'max_los' => $r->max_los,
                'closed' => (bool) $r->stop_sell, 'cta' => (bool) $r->cta, 'ctd' => (bool) $r->ctd,
            ])->values(),
        ])->values()->all();
    }

    /** Sends a booking message the way a real channel would: signed, parsed by the provider, imported. */
    public function send(ChannelConnection $c, array $message): array
    {
        $this->assertTest($c);
        $secret = (string) (($c->credentials ?? [])['webhook_secret'] ?? '');
        $body = json_encode($message, JSON_UNESCAPED_SLASHES);
        $request = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CHANNEL_SIGNATURE' => hash_hmac('sha256', $body, $secret)], $body);
        if (! in_array($c->status, ['active', 'error'], true)) {
            throw ValidationException::withMessages(['connection' => __('channels.errors.not_active')]);
        }
        $result = [];
        foreach ($this->registry->provider($c)->parseWebhook($c, $request) as $booking) {
            $result = $this->inbound->ingest($c, $booking);
        }

        return $result;
    }

    /** The channel changes the stay dates of a booking it sent earlier (next version). */
    public function modify(ChannelConnection $c, \App\Models\ChannelReservation $record, string $checkIn, string $checkOut): array
    {
        $p = (array) $record->payload;
        $first = (array) ($p['rooms'][0] ?? []);
        $price = (string) (collect((array) ($first['nightly'] ?? []))->first() ?? '0');
        $nightly = [];
        for ($d = strtotime($checkIn); $d < strtotime($checkOut); $d += 86400) {
            $nightly[date('Y-m-d', $d)] = $price;
        }
        $p['type'] = 'modify';
        $p['version'] = (int) $record->version + 1;
        $p['rooms'][0] = ['check_in' => $checkIn, 'check_out' => $checkOut, 'nightly' => $nightly] + $first;

        return $this->send($c, $p);
    }

    public function cancel(ChannelConnection $c, \App\Models\ChannelReservation $record): array
    {
        return $this->send($c, ['type' => 'cancel', 'external_ref' => $record->external_ref, 'version' => (int) $record->version + 1]);
    }

    /** The next $n updates sent to the Test Channel are refused. */
    public function simulateOutage(ChannelConnection $c, int $n = 3): void
    {
        $this->assertTest($c);
        $c->forceFill(['settings' => array_merge($c->settings ?? [], ['simulate_failures' => $n])])->save();
    }

    public function newMessage(array $in): array
    {
        $nights = max(1, (int) ((strtotime($in['check_out']) - strtotime($in['check_in'])) / 86400));
        $nightly = [];
        for ($i = 0; $i < $nights; $i++) {
            $nightly[date('Y-m-d', strtotime($in['check_in'].' +'.$i.' day'))] = number_format((float) $in['price'], 2, '.', '');
        }

        return [
            'type' => 'new', 'external_ref' => $in['external_ref'] ?? ('TEST-'.strtoupper(substr(bin2hex(random_bytes(4)), 0, 7))), 'version' => 1,
            'currency' => $in['currency'] ?? null,
            'guest' => ['first_name' => $in['first_name'], 'last_name' => $in['last_name'], 'email' => $in['email'] ?? null, 'country' => 'GB'],
            'rooms' => [['room_id' => $in['room_id'], 'rate_id' => $in['rate_id'] ?? null, 'check_in' => $in['check_in'], 'check_out' => $in['check_out'],
                'adults' => (int) ($in['adults'] ?? 2), 'children' => 0, 'infants' => 0, 'nightly' => $nightly]],
        ];
    }

    private function assertTest(ChannelConnection $c): void
    {
        if ($c->provider !== 'test') {
            throw ValidationException::withMessages(['connection' => __('channels.errors.test_only')]);
        }
    }
}
