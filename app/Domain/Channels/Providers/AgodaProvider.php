<?php

namespace App\Domain\Channels\Providers;

use App\Domain\Channels\AriBatcher;
use App\Domain\Channels\Contracts\ChannelProvider;
use App\Domain\Channels\Data\AriUpdate;
use App\Domain\Channels\Data\InboundReservation;
use App\Domain\Channels\Data\ProviderResult;
use App\Models\ChannelConnection;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Agoda YCS (XML over HTTPS at supply.agoda.com/api): GetProduct (type 5), SetAriV2 (type 10),
 * GetBookingList (type 3) and GetBookingDetail (type 4).
 *
 * Written from Agoda's public developer documentation. It stays switched off (env OZ_CHANNEL_AGODA) until E2X has YCS
 * partner access and has passed Agoda's certification; points marked "to confirm" must be checked against Agoda first.
 * The hotel id of the connection is the Agoda property id. Credentials: OAuth client id / secret (api_key is the
 * older method that Agoda ends at the close of 2026).
 */
class AgodaProvider implements ChannelProvider
{
    public function credentialFields(): array
    {
        return [
            // Either the OAuth pair or (until Agoda ends it, end of 2026) the older API key; the connection test tells which one is missing.
            ['key' => 'client_id', 'type' => 'text', 'required' => false],
            ['key' => 'client_secret', 'type' => 'secret', 'required' => false],
            ['key' => 'api_key', 'type' => 'secret', 'required' => false],
        ];
    }

    public function testConnection(ChannelConnection $connection): ProviderResult
    {
        try {
            $response = $this->call($connection, $this->productRequest($connection), 60);
        } catch (ConnectionException) {
            return ProviderResult::fail(__('channels.agoda.unreachable'), true);
        }
        if (in_array($response->status(), [401, 403], true)) {
            return ProviderResult::fail(__('channels.agoda.bad_login'), false, null, $this->trim($response->body()));
        }
        $problem = $this->problem($response);
        if ($problem !== null) {
            return ProviderResult::fail($problem['message'], $problem['retryable'], null, $this->trim($response->body()));
        }

        return ProviderResult::ok(__('channels.agoda.connected', ['hotel' => $connection->external_hotel_id]), null, $this->trim($response->body()));
    }

    public function listings(ChannelConnection $connection): array
    {
        $response = $this->call($connection, $this->productRequest($connection), 60);
        $xp = $this->xpath($response->body());
        if ($xp === null) {
            return ['rooms' => [], 'rates' => []];
        }
        $rooms = [];
        $names = [];
        foreach ($xp->query("//*[local-name()='room']") as $room) {
            /** @var DOMElement $room */
            $id = $room->getAttribute('room_id');
            $names[$id] = $room->getAttribute('room_name') ?: $id;
            $rooms[] = ['id' => $id, 'name' => $names[$id]];
        }
        $rateNames = [];
        foreach ($xp->query("//*[local-name()='rateplan']") as $plan) {
            /** @var DOMElement $plan */
            $rateNames[$plan->getAttribute('rateplan_id')] = $plan->getAttribute('rateplan_name') ?: $plan->getAttribute('rateplan_id');
        }
        $rates = [];
        foreach ($xp->query("//*[local-name()='product']") as $product) {
            /** @var DOMElement $product */
            $room = $product->getAttribute('room_id');
            $rate = $product->getAttribute('rateplan_id');
            $rates[] = ['id' => $rate, 'name' => ($names[$room] ?? $room).' — '.($rateNames[$rate] ?? $rate), 'room_id' => $room];
        }

        return ['rooms' => $rooms, 'rates' => $rates];
    }

    public function pushAri(ChannelConnection $connection, array $updates): ProviderResult
    {
        $currency = $this->currency($connection);
        $logRequest = [];
        $logResponse = [];
        $sent = 0;
        // One calendar month per request, so no request grows long; within a month equal values are grouped. A month is only
        // cut further when it would pass max_request_bytes (Agoda caps the request size).
        foreach (AriBatcher::months(AriBatcher::merge($updates)) as $segments) {
            [$inventory, $rates] = $this->elements($segments, $currency);
            foreach ($this->requests($connection->external_hotel_id, $inventory, $rates) as $xml) {
                $sent++;
                $logRequest[] = $xml;
                try {
                    $response = $this->call($connection, $xml, 120);
                } catch (ConnectionException) {
                    return ProviderResult::fail(__('channels.agoda.unreachable'), true, $this->trim(implode("\n\n", $logRequest)), $this->trim(implode("\n\n", $logResponse)));
                }
                $logResponse[] = $response->status().' '.$this->trim($response->body());
                $problem = $this->problem($response);
                if ($problem !== null) {
                    return ProviderResult::fail($problem['message'], $problem['retryable'], $this->trim(implode("\n\n", $logRequest)), $this->trim(implode("\n\n", $logResponse)));
                }
            }
        }
        if ($sent === 0) {
            return ProviderResult::ok('Nothing to send');
        }

        return ProviderResult::ok(__('channels.agoda.accepted', ['count' => count($updates)]), $this->trim(implode("\n\n", $logRequest)), $this->trim(implode("\n\n", $logResponse)));
    }

    /**
     * Update elements of one month. Same room + rate + values are written once: long runs as a date range, scattered
     * short runs (weekends, single days) together as date lists of at most 31 dates.
     *
     * @param  list<AriUpdate>  $segments
     * @return array{0: list<string>, 1: list<string>} inventory and rate elements
     */
    private function elements(array $segments, string $currency): array
    {
        $inventory = [];
        $rates = [];
        foreach (AriBatcher::groupByValues($segments) as $g) {
            $v = $g['values'];
            if ($g['rate'] === null) {
                $body = (isset($v['availability']) ? '<allotment>'.min(999, max(0, (int) $v['availability'])).'</allotment>' : '')
                    .(isset($v['stop_sell']) ? '<restrictions><closed>'.($v['stop_sell'] ? 'true' : 'false').'</closed></restrictions>' : '');
                if ($body === '') {
                    continue;
                }
                foreach (array_chunk(AriBatcher::dates($g['ranges']), 31) as $dates) {
                    $inventory[] = '<update room_id="'.$this->esc($g['room']).'"><date_values>'.$this->values($dates).'</date_values>'.$body.'</update>';
                }

                continue;
            }
            $prices = isset($v['price']) ? '<prices currency="'.$currency.'"><normal default="'.$this->esc((string) $v['price']).'"/></prices>' : '';
            $restrictions = '';
            foreach (['stop_sell' => 'closed', 'ctd' => 'ctd', 'cta' => 'cta'] as $key => $tag) {
                if (isset($v[$key])) {
                    $restrictions .= '<'.$tag.'>'.($v[$key] ? 'true' : 'false').'</'.$tag.'>';
                }
            }
            if (isset($v['min_los']) || isset($v['max_los'])) {
                $restrictions .= '<los>'.(isset($v['min_los']) ? '<min>'.min(99, max(1, (int) $v['min_los'])).'</min>' : '').(isset($v['max_los']) ? '<max>'.min(99, max(0, (int) $v['max_los'])).'</max>' : '').'</los>';
            }
            $tail = $prices.($restrictions !== '' ? '<restrictions>'.$restrictions.'</restrictions>' : '');
            if ($tail === '') {
                continue;
            }
            $open = '<update room_id="'.$this->esc($g['room']).'" rateplan_id="'.$this->esc((string) $g['rate']).'">';
            $short = [];
            foreach ($g['ranges'] as [$from, $to]) {
                if ((strtotime($to) - strtotime($from)) / 86400 + 1 > 7) {
                    $rates[] = $open.'<date_range from="'.$from.'" to="'.$to.'"/>'.$tail.'</update>';
                } else {
                    $short[] = [$from, $to];
                }
            }
            foreach (array_chunk(AriBatcher::dates($short), 31) as $dates) {
                $rates[] = $open.'<date_values>'.$this->values($dates).'</date_values>'.$tail.'</update>';
            }
        }

        return [$inventory, $rates];
    }

    /** @param  list<string>  $dates */
    private function values(array $dates): string
    {
        return implode('', array_map(fn ($d) => '<value>'.$d.'</value>', $dates));
    }

    /**
     * @param  list<string>  $inventory
     * @param  list<string>  $rates
     * @return list<string> complete request documents, each below the size limit
     */
    private function requests(string $propertyId, array $inventory, array $rates): array
    {
        $limit = max(10000, (int) config('channels.providers.agoda.max_request_bytes', 900000));
        $head = fn () => '<?xml version="1.0" encoding="UTF-8"?><request timestamp="'.$this->now().'" type="10"><criteria property_id="'.$this->esc($propertyId).'">';
        $out = [];
        $inv = [];
        $rate = [];
        $size = 0;
        $close = function () use (&$out, &$inv, &$rate, &$size, $head) {
            if ($inv !== [] || $rate !== []) {
                $out[] = $head().($inv !== [] ? '<inventory>'.implode('', $inv).'</inventory>' : '').($rate !== [] ? '<rate>'.implode('', $rate).'</rate>' : '').'</criteria></request>';
            }
            $inv = $rate = [];
            $size = 0;
        };
        foreach ([['inv', $inventory], ['rate', $rates]] as [$kind, $elements]) {
            foreach ($elements as $el) {
                if ($size + strlen($el) > $limit) {
                    $close();
                }
                $kind === 'inv' ? $inv[] = $el : $rate[] = $el;
                $size += strlen($el);
            }
        }
        $close();

        return $out;
    }

    public function parseWebhook(ChannelConnection $connection, Request $request): array
    {
        // Agoda bookings are fetched (pullReservations); this channel pushes nothing to us.
        abort(404);
    }

    public function pullReservations(ChannelConnection $connection): array
    {
        $from = (int) $connection->setting('agoda_last_poll', time() - 2 * 86400) - 600;   // overlap: acknowledge="0" filters repeats
        $to = time();
        $list = '<?xml version="1.0" encoding="UTF-8"?><request timestamp="'.$this->now().'" type="3"><criteria from="'.gmdate('Y-m-d\TH:i:sP', $from).'" to="'.gmdate('Y-m-d\TH:i:sP', $to).'">'
            .'<property id="'.$this->esc($connection->external_hotel_id).'"/></criteria></request>';
        $response = $this->call($connection, $list, 300);
        if (! $response->successful()) {
            return [];
        }
        $ids = $this->undeliveredIds($response->body());
        $out = [];
        foreach (array_chunk($ids, 50) as $chunk) {
            $detail = '<?xml version="1.0" encoding="UTF-8"?><request timestamp="'.$this->now().'" type="4"><criteria language="EN"><property id="'.$this->esc($connection->external_hotel_id).'">'
                .implode('', array_map(fn ($id) => '<booking id="'.$this->esc($id).'"/>', $chunk)).'</property></criteria></request>';
            $res = $this->call($connection, $detail, 300);
            if ($res->successful()) {
                // Fetching the detail is how Agoda records delivery (no separate confirmation call is documented).
                foreach ($this->parseDetails($res->body()) as $booking) {
                    $out[] = $booking;
                }
            }
        }
        $connection->forceFill(['settings' => array_merge($connection->settings ?? [], ['agoda_last_poll' => $to])])->saveQuietly();

        return $out;
    }

    public function acknowledge(ChannelConnection $connection, InboundReservation $booking, bool $ok, ?string $pmsRef, ?string $error): void
    {
        // Agoda treats a retrieved booking as delivered; there is nothing to send back.
    }

    /** @return list<string> booking ids that were not delivered yet (acknowledge="0") */
    public function undeliveredIds(string $xml): array
    {
        $xp = $this->xpath($xml);
        $ids = [];
        foreach ($xp?->query("//*[local-name()='booking']") ?? [] as $b) {
            /** @var DOMElement $b */
            if ($b->getAttribute('booking_id') !== '' && $b->getAttribute('acknowledge') !== '1') {
                $ids[] = $b->getAttribute('booking_id');
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return list<InboundReservation> */
    public function parseDetails(string $xml): array
    {
        $xp = $this->xpath($xml);
        $out = [];
        foreach ($xp?->query("//*[local-name()='booking'][@booking_id]") ?? [] as $b) {
            /** @var DOMElement $b */
            $status = $b->getAttribute('status');
            $type = str_contains($status, 'Cancel') ? 'cancel' : (str_contains($status, 'Amend') ? 'modify' : 'new');
            $action = $b->getAttribute('last_action');
            $version = $type === 'new' ? 1 : max(2, (int) strtotime($action ?: 'now'));
            $arrival = substr($b->getAttribute('arrival'), 0, 10);
            $departure = substr($b->getAttribute('departure'), 0, 10);
            $count = max(1, (int) $b->getAttribute('room_count'));

            // Room prices per night; to confirm: with room_count > 1 the amounts are assumed to cover all rooms.
            $nightly = [];
            $currency = $xp->evaluate("string(.//*[local-name()='prices']/@currency)", $b) ?: null;
            foreach ($xp->query(".//*[local-name()='price'][@type='Room']", $b) as $p) {
                /** @var DOMElement $p */
                $day = substr($p->getAttribute('date'), 0, 10);
                if ($day !== '') {
                    $nightly[$day] = number_format($this->priceOf($p) / $count, 2, '.', '');
                }
            }
            if ($nightly === [] && $arrival !== '' && $departure !== '') {
                $total = $this->priceOf($xp->query(".//*[local-name()='prices']", $b)->item(0));
                $nights = max(1, (int) round((strtotime($departure) - strtotime($arrival)) / 86400));
                for ($d = strtotime($arrival); $d < strtotime($departure); $d += 86400) {
                    $nightly[date('Y-m-d', $d)] = number_format($total / $nights / $count, 2, '.', '');
                }
            }
            ksort($nightly);
            $adults = max(1, (int) $b->getAttribute('adults'));
            $room = ['room_id' => $b->getAttribute('room_id'), 'rate_id' => $b->getAttribute('rateplan_id') ?: null, 'check_in' => $arrival, 'check_out' => $departure,
                'adults' => max(1, intdiv($adults, $count)), 'children' => intdiv((int) $b->getAttribute('children'), $count), 'infants' => 0, 'nightly' => $nightly];

            $customer = $xp->query(".//*[local-name()='customer']", $b)->item(0);
            $nationality = $customer instanceof DOMElement ? strtoupper(trim($customer->getAttribute('nationality'))) : '';
            $guest = [
                'first_name' => $customer instanceof DOMElement ? $customer->getAttribute('first_name') : '',
                'last_name' => $customer instanceof DOMElement ? ($customer->getAttribute('last_name') ?: 'Guest') : 'Guest',
                'email' => $customer instanceof DOMElement ? ($customer->getAttribute('email') ?: null) : null,
                'phone' => $customer instanceof DOMElement ? ($customer->getAttribute('phone') ?: null) : null,
                'country' => preg_match('/^[A-Z]{2}$/', $nationality) ? $nationality : null,
            ];
            $requests = [];
            foreach ($xp->query(".//*[local-name()='request'][@request_name]", $b) as $r) {
                /** @var DOMElement $r */
                $requests[] = $r->getAttribute('request_name');
            }

            $out[] = new InboundReservation($type, $b->getAttribute('booking_id'), $version, $guest, array_fill(0, $count, $room), $currency, null,
                $requests ? implode(', ', $requests) : null, ['source' => 'agoda', 'status' => $status]);
        }

        return $out;
    }

    // ---------------------------------------------------------------- helpers

    private function productRequest(ChannelConnection $c): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><request type="5" timestamp="'.$this->now().'"><criteria language="EN"><property id="'.$this->esc($c->external_hotel_id).'"><rooms></rooms><rateplans></rateplans></property></criteria></request>';
    }

    private function now(): string
    {
        return (string) (int) round(microtime(true) * 1000);
    }

    private function call(ChannelConnection $c, string $xml, int $timeout, bool $retried = false): Response
    {
        $cred = (array) ($c->credentials ?? []);
        $useToken = ($cred['client_id'] ?? '') !== '' && ($cred['client_secret'] ?? '') !== '';
        $request = Http::timeout($timeout)->withBody($xml, 'application/xml');
        $url = $this->base();
        if ($useToken) {
            $request = $request->withToken($this->token($c, $retried));
        } else {
            $url .= '?apiKey='.rawurlencode((string) ($cred['api_key'] ?? ''));
        }
        $response = $request->post($url);
        // An expired token answers 403: get a new one once and try again.
        if ($useToken && $response->status() === 403 && ! $retried) {
            return $this->call($c, $xml, $timeout, true);
        }

        return $response;
    }

    private function token(ChannelConnection $c, bool $fresh): string
    {
        $key = 'agoda-token:'.$c->id;
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, 3300, function () use ($c) {
            $cred = (array) $c->credentials;
            $res = Http::timeout(30)->asJson()->post((string) config('channels.providers.agoda.token_url', 'https://supply.agoda.com/token-based-authentication/exchange'),
                ['clientId' => $cred['client_id'], 'clientSecret' => $cred['client_secret']]);
            $json = (array) $res->json();
            // To confirm: the name of the token field in Agoda's answer.
            foreach (['token', 'access_token', 'accessToken', 'jwt'] as $k) {
                if (! empty($json[$k]) && is_string($json[$k])) {
                    return $json[$k];
                }
                if (! empty($json['data'][$k]) && is_string($json['data'][$k])) {
                    return $json['data'][$k];
                }
            }

            return '';
        });
    }

    private function base(): string
    {
        return (string) config('channels.providers.agoda.api_url', 'https://supply.agoda.com/api');
    }

    /** @return array{message: string, retryable: bool}|null null when Agoda accepted the request */
    private function problem(Response $response): ?array
    {
        $errors = [];
        $xp = $this->xpath($response->body());
        foreach ($xp?->query("//*[local-name()='error']") ?? [] as $e) {
            /** @var DOMElement $e */
            $errors[] = trim($e->getAttribute('code').' '.$e->getAttribute('description'));
        }
        $partial = $xp?->evaluate("string(/*/@status)") === 'PartialSuccess';
        if ($response->successful() && $errors === [] && ! $partial) {
            return null;
        }
        $retryable = $errors === [] && ($response->status() >= 500 || in_array($response->status(), [408, 429], true));

        return ['message' => $errors !== [] ? implode('; ', array_slice($errors, 0, 5)) : ('HTTP '.$response->status()), 'retryable' => $retryable];
    }

    private function priceOf(?\DOMNode $node): float
    {
        if (! $node instanceof DOMElement) {
            return 0.0;
        }
        // Without tax, guest price first (the PMS adds its own tax); to confirm which amount fits each payment model.
        foreach (['sell_exclusive_amt', 'net_exclusive_amt', 'sell_inclusive_amt', 'net_inclusive_amt'] as $a) {
            if ($node->getAttribute($a) !== '') {
                return (float) $node->getAttribute($a);
            }
        }

        return 0.0;
    }

    private function currency(ChannelConnection $c): string
    {
        return (string) (DB::table('properties')->where('id', $c->property_id)->value('currency_code') ?? 'USD');
    }

    private function xpath(string $xml): ?DOMXPath
    {
        if (trim($xml) === '') {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument;
        $ok = $doc->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $ok ? new DOMXPath($doc) : null;
    }

    private function esc(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function trim(string $t): string
    {
        return mb_substr($t, 0, 20000);
    }
}
