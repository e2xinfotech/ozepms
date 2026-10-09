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
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Booking.com connectivity (OTA XML): availability and restrictions (OTA_HotelAvailNotif), rates
 * (OTA_HotelRateAmountNotif), room / rate list (xml/roomrates), reservations by polling
 * (OTA_HotelResNotif / OTA_HotelResModifyNotif) with acknowledgement.
 *
 * Written from Booking.com's public connectivity documentation. It stays switched off
 * (config channels.providers.booking_com, env OZ_CHANNEL_BOOKING_COM) until E2X holds sandbox access and has passed
 * Booking.com's certification; the points marked "to confirm" must be checked against the sandbox first.
 * The hotel id of the connection is the Booking.com property id; credentials are the machine account.
 */
class BookingComProvider implements ChannelProvider
{
    private const NS_OTA = 'http://www.opentravel.org/OTA/2003/05';

    public function credentialFields(): array
    {
        return [
            ['key' => 'username', 'type' => 'text', 'required' => true],
            ['key' => 'password', 'type' => 'secret', 'required' => true],
            // after_tax (default) or before_tax: must match the tax setting of the rooms on Booking.com.
            ['key' => 'price_basis', 'type' => 'text', 'required' => false],
        ];
    }

    public function testConnection(ChannelConnection $connection): ProviderResult
    {
        try {
            $response = $this->client($connection, 30)->get($this->supply('/hotels/ota/OTA_HotelProductNotif'), ['HotelCode' => $connection->external_hotel_id]);
        } catch (ConnectionException $e) {
            return ProviderResult::fail(__('channels.booking_com.unreachable'), true);
        }
        if (in_array($response->status(), [401, 403], true)) {
            return ProviderResult::fail(__('channels.booking_com.bad_login'), false, null, $this->trim($response->body()));
        }
        if (! $response->successful()) {
            return $this->failed($response, null);
        }

        return ProviderResult::ok(__('channels.booking_com.connected', ['hotel' => $connection->external_hotel_id]), null, $this->trim($response->body()));
    }

    public function listings(ChannelConnection $connection): array
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><request><hotel_id>'.$this->esc($connection->external_hotel_id).'</hotel_id></request>';
        $response = $this->client($connection, 60)->withBody($xml, 'application/xml')->post($this->supply('/hotels/xml/roomrates'));
        if (! $response->successful()) {
            return ['rooms' => [], 'rates' => []];
        }
        $rooms = [];
        $rates = [];
        $xp = $this->xpath($response->body());
        foreach ($xp?->query("//*[local-name()='room']") ?? [] as $room) {
            /** @var DOMElement $room */
            $id = $room->getAttribute('id');
            $rooms[] = ['id' => $id, 'name' => $room->getAttribute('room_name') ?: $id];
            foreach ($xp->query(".//*[local-name()='rate']", $room) as $rate) {
                /** @var DOMElement $rate */
                $rates[] = ['id' => $rate->getAttribute('id'), 'name' => ($room->getAttribute('room_name') ?: $id).' — '.($rate->getAttribute('rate_name') ?: $rate->getAttribute('id')), 'room_id' => $id];
            }
        }

        return ['rooms' => $rooms, 'rates' => $rates];
    }

    public function pushAri(ChannelConnection $connection, array $updates): ProviderResult
    {
        [$currency, $decimals] = $this->currency($connection);
        $afterTax = ($connection->credentials['price_basis'] ?? 'after_tax') !== 'before_tax';

        // One value set per room / rate / date, then one request per hotel, calendar month and message kind
        // (Booking.com takes one month per request); identical messages are sent once and a long month is cut at max_messages.
        $updates = AriBatcher::merge($updates);
        $max = max(1, (int) config('channels.providers.booking_com.max_messages', 500));

        $logRequest = [];
        $logResponse = [];
        foreach (AriBatcher::months($updates) as $segments) {
            [$avail, $rate] = $this->messages($segments, $currency, $decimals, $afterTax);
            foreach ([['/hotels/ota/OTA_HotelAvailNotif', $avail, 'OTA_HotelAvailNotifRQ', 'AvailStatusMessages'], ['/hotels/ota/OTA_HotelRateAmountNotif', $rate, 'OTA_HotelRateAmountNotifRQ', 'RateAmountMessages']] as [$path, $messages, $root, $wrap]) {
                foreach (array_chunk(array_values(array_unique($messages)), $max) as $part) {
                    $body = $this->envelope($root, $wrap, $part);
                    $logRequest[] = $path."\n".$body;
                    try {
                        $response = $this->client($connection, 120)->withBody($body, 'application/xml')->post($this->supply($path));
                    } catch (ConnectionException $e) {
                        return ProviderResult::fail(__('channels.booking_com.unreachable'), true, implode("\n\n", $logRequest), implode("\n\n", $logResponse));
                    }
                    $logResponse[] = $response->status().' '.$this->trim($response->body());
                    $problem = $this->problem($response);
                    if ($problem !== null) {
                        return ProviderResult::fail($problem['message'], $problem['retryable'], implode("\n\n", $logRequest), implode("\n\n", $logResponse));
                    }
                }
            }
        }

        return ProviderResult::ok(__('channels.booking_com.accepted', ['count' => count($updates)]), $this->trim(implode("\n\n", $logRequest)), $this->trim(implode("\n\n", $logResponse)));
    }

    public function parseWebhook(ChannelConnection $connection, Request $request): array
    {
        // Booking.com does not push bookings; they are fetched (pullReservations).
        abort(404);
    }

    public function pullReservations(ChannelConnection $connection): array
    {
        $out = [];
        foreach ([['/hotels/ota/OTA_HotelResNotif', false], ['/hotels/ota/OTA_HotelResModifyNotif', true]] as [$path, $modify]) {
            $response = $this->client($connection, 300)->get($this->secure($path), ['hotel_ids' => $connection->external_hotel_id, 'limit' => 100]);
            if (! $response->successful() || trim($response->body()) === '') {
                continue;
            }
            foreach ($this->parseReservations($response->body(), $modify) as $booking) {
                $out[] = $booking;
            }
        }

        return $out;
    }

    public function acknowledge(ChannelConnection $connection, InboundReservation $booking, bool $ok, ?string $pmsRef, ?string $error): void
    {
        $modify = $booking->type !== 'new';
        $root = $modify ? 'OTA_HotelResModifyNotifRS' : 'OTA_HotelResNotifRS';
        $stamp = gmdate('Y-m-d\TH:i:s');
        $ref = $this->esc($booking->externalRef);
        if ($ok) {
            $wrap = $modify ? 'HotelResModifies' : 'HotelReservations';
            $item = $modify ? 'HotelResModify' : 'HotelReservation';
            $type = $modify ? '' : ' ResID_Type="14"';
            $xml = '<?xml version="1.0" encoding="UTF-8"?><'.$root.' TimeStamp="'.$stamp.'" Target="Production"><Success/><'.$wrap.'><'.$item.'><ResGlobalInfo><HotelReservationIDs>'
                .'<HotelReservationID'.$type.' ResID_Value="'.$ref.'"/></HotelReservationIDs></ResGlobalInfo></'.$item.'></'.$wrap.'></'.$root.'>';
        } else {
            $xml = '<?xml version="1.0" encoding="UTF-8"?><'.$root.' TimeStamp="'.$stamp.'" Target="Production"><Errors><Error Code="193" RecordID="'.$ref.'" ShortText="'
                .$this->esc(mb_substr((string) ($error ?: 'Reservation could not be processed'), 0, 120)).'"/></Errors></'.$root.'>';
        }
        try {
            $this->client($connection, 300)->withBody($xml, 'application/xml')->post($this->secure($modify ? '/hotels/ota/OTA_HotelResModifyNotif' : '/hotels/ota/OTA_HotelResNotif'));
        } catch (ConnectionException) {
            // Not acknowledged: Booking.com offers the booking again and sends the hotel a fallback e-mail after the deadline.
        }
    }

    /** @return list<InboundReservation> */
    public function parseReservations(string $xml, bool $modify): array
    {
        $xp = $this->xpath($xml);
        if ($xp === null) {
            return [];
        }
        $stamp = $xp->evaluate("string(/*/@TimeStamp)");
        $out = [];
        $nodes = $xp->query($modify ? "//*[local-name()='HotelResModify']" : "//*[local-name()='HotelReservation']");
        foreach ($nodes ?? [] as $node) {
            /** @var DOMElement $node */
            $idNode = $xp->query(".//*[local-name()='HotelReservationID'][@ResID_Value]", $node)->item(0);
            if (! $idNode instanceof DOMElement) {
                continue;
            }
            $ref = $idNode->getAttribute('ResID_Value');
            $date = $idNode->getAttribute('ResID_Date') ?: $stamp;
            $status = strtolower((string) $node->getAttribute('ResStatus'));
            // To confirm in the sandbox: how a cancellation is marked in OTA_HotelResModifyNotif (ResStatus is assumed).
            $type = $modify ? (str_contains($status, 'cancel') ? 'cancel' : 'modify') : (str_contains($status, 'cancel') ? 'cancel' : 'new');
            $version = $type === 'new' ? 1 : max(2, (int) strtotime($date ?: 'now'));

            $rooms = [];
            $currency = null;
            foreach ($xp->query(".//*[local-name()='RoomStay']", $node) as $stay) {
                $nightly = [];
                $rate = null;
                foreach ($xp->query(".//*[local-name()='RoomRate']", $stay) as $rr) {
                    /** @var DOMElement $rr */
                    $day = $rr->getAttribute('EffectiveDate');
                    $rate ??= $rr->getAttribute('RatePlanCode') ?: null;
                    $total = $xp->query(".//*[local-name()='Total']", $rr)->item(0);
                    if ($day !== '' && $total instanceof DOMElement) {
                        $nightly[$day] = $this->amount($total);
                        $currency ??= $total->getAttribute('CurrencyCode') ?: null;
                    }
                }
                ksort($nightly);
                $days = array_keys($nightly);
                if ($days === []) {
                    continue;
                }
                $adults = 0;
                $children = 0;
                foreach ($xp->query(".//*[local-name()='GuestCount']", $stay) as $gc) {
                    /** @var DOMElement $gc */
                    // AgeQualifyingCode 10 = adult, 8 = child (OTA code list); no code counts as adult.
                    $code = $gc->getAttribute('AgeQualifyingCode');
                    $count = (int) $gc->getAttribute('Count');
                    $code === '8' ? $children += $count : $adults += $count;
                }
                $roomType = $xp->query(".//*[local-name()='RoomType']", $stay)->item(0);
                $rooms[] = [
                    'room_id' => $roomType instanceof DOMElement ? $roomType->getAttribute('RoomTypeCode') : '',
                    'rate_id' => $rate, 'check_in' => $days[0], 'check_out' => date('Y-m-d', strtotime(end($days).' +1 day')),
                    'adults' => max(1, $adults), 'children' => $children, 'infants' => 0, 'nightly' => $nightly,
                ];
            }

            $customer = $xp->query(".//*[local-name()='ResGlobalInfo']//*[local-name()='Customer']", $node)->item(0)
                ?? $xp->query(".//*[local-name()='ResGuests']//*[local-name()='Customer']", $node)->item(0);
            $guest = ['first_name' => '', 'last_name' => '', 'email' => null, 'phone' => null, 'country' => null];
            if ($customer instanceof DOMElement) {
                $guest['first_name'] = trim($xp->evaluate("string(.//*[local-name()='GivenName'])", $customer));
                $guest['last_name'] = trim($xp->evaluate("string(.//*[local-name()='Surname'])", $customer));
                $guest['email'] = trim($xp->evaluate("string(.//*[local-name()='Email'])", $customer)) ?: null;
                $guest['country'] = trim($xp->evaluate("string(.//*[local-name()='CountryName']/@Code)", $customer)) ?: null;
            }
            if ($guest['last_name'] === '') {
                $guest['last_name'] = trim($xp->evaluate("string(.//*[local-name()='ResGuests']//*[local-name()='Surname'])", $node)) ?: 'Guest';
            }
            $notes = trim($xp->evaluate("string(.//*[local-name()='ResGlobalInfo']//*[local-name()='Comment']/*[local-name()='Text'])", $node));

            $out[] = new InboundReservation($type, $ref, $version, $guest, $rooms, $currency, null, $notes !== '' ? $notes : null, ['source' => 'booking_com']);
        }

        return $out;
    }

    // ---------------------------------------------------------------- ARI message building

    /** @return array{0: list<string>, 1: list<string>} availability / restriction messages and rate messages */
    private function messages(array $segments, string $currency, int $decimals, bool $afterTax): array
    {
        $restrictions = [];
        $limits = [];
        $rates = [];
        foreach ($segments as $u) {
            /** @var AriUpdate $u */
            $control = fn (bool $withRate) => '<StatusApplicationControl Start="'.$u->from.'" End="'.$u->to.'" InvTypeCode="'.$this->esc($u->roomId).'"'
                .($withRate && $u->rateId !== null ? ' RatePlanCode="'.$this->esc($u->rateId).'"' : '').'/>';
            $restrict = fn (string $status, ?string $kind) => '<AvailStatusMessage>'.$control(true).'<RestrictionStatus Status="'.$status.'"'.($kind ? ' Restriction="'.$kind.'"' : '').'/></AvailStatusMessage>';

            if ($u->stopSell !== null) {
                $restrictions[] = $restrict($u->stopSell ? 'Close' : 'Open', null);
            }
            if ($u->availability !== null && $u->rateId === null) {
                $limits[] = '<AvailStatusMessage BookingLimit="'.min(254, max(0, $u->availability)).'">'.$control(false).'</AvailStatusMessage>';
            }
            if ($u->rateId !== null) {
                if ($u->cta !== null) {
                    $restrictions[] = $restrict($u->cta ? 'Close' : 'Open', 'Arrival');
                }
                if ($u->ctd !== null) {
                    $restrictions[] = $restrict($u->ctd ? 'Close' : 'Open', 'Departure');
                }
                if ($u->minLos !== null || $u->maxLos !== null) {
                    $los = '';
                    if ($u->minLos !== null) {
                        $los .= '<LengthOfStay Time="'.max(0, $u->minLos).'" MinMaxMessageType="SetMinLOS"/>';
                    }
                    if ($u->maxLos !== null) {
                        $los .= '<LengthOfStay Time="'.max(0, $u->maxLos).'" MinMaxMessageType="SetMaxLOS"/>';
                    }
                    $restrictions[] = '<AvailStatusMessage>'.$control(true).'<LengthsOfStay ArrivalDateBased="1">'.$los.'</LengthsOfStay></AvailStatusMessage>';
                }
                if ($u->price !== null) {
                    $attr = $afterTax ? 'AmountAfterTax' : 'AmountBeforeTax';
                    $rates[] = '<RateAmountMessage><StatusApplicationControl Start="'.$u->from.'" End="'.$u->to.'" RatePlanCode="'.$this->esc($u->rateId).'" InvTypeCode="'.$this->esc($u->roomId).'"/>'
                        .'<Rates><Rate><BaseByGuestAmts><BaseByGuestAmt '.$attr.'="'.$this->minor($u->price, $decimals).'" DecimalPlaces="'.$decimals.'" CurrencyCode="'.$currency.'"/></BaseByGuestAmts></Rate></Rates></RateAmountMessage>';
                }
            }
        }

        // A closed room has to be opened before availability is set, so restrictions go first.
        return [array_merge($restrictions, $limits), $rates];
    }

    private function envelope(string $root, string $wrap, array $messages): string
    {
        $ns = $root === 'OTA_HotelRateAmountNotifRQ' ? ' xmlns="'.self::NS_OTA.'" TimeStamp="'.gmdate('Y-m-d\TH:i:s').'" Version="3.000"' : '';

        return '<?xml version="1.0" encoding="UTF-8"?><'.$root.$ns.'><'.$wrap.'>'.implode('', $messages).'</'.$wrap.'></'.$root.'>';
    }

    // ---------------------------------------------------------------- HTTP and parsing helpers

    private function client(ChannelConnection $c, int $timeout): PendingRequest
    {
        $cred = (array) ($c->credentials ?? []);

        return Http::withBasicAuth((string) ($cred['username'] ?? ''), (string) ($cred['password'] ?? ''))
            ->withHeaders(['Accept-Version' => '1.1', 'Accept' => 'application/xml'])
            ->timeout($timeout);
    }

    private function supply(string $path): string
    {
        return rtrim((string) config('channels.providers.booking_com.supply_url', 'https://supply-xml.booking.com'), '/').$path;
    }

    private function secure(string $path): string
    {
        return rtrim((string) config('channels.providers.booking_com.secure_url', 'https://secure-supply-xml.booking.com'), '/').$path;
    }

    /** @return array{message: string, retryable: bool}|null null when Booking.com accepted the request */
    private function problem(Response $response): ?array
    {
        $body = $response->body();
        $errors = $this->errors($body);
        if ($response->successful() && $errors === []) {
            return null;
        }
        $retryable = in_array($response->status(), [408, 425, 429], true) || $response->status() >= 500;

        return ['message' => $errors !== [] ? implode('; ', $errors) : ('HTTP '.$response->status()), 'retryable' => $retryable && $errors === []];
    }

    private function failed(Response $response, ?string $request): ProviderResult
    {
        $problem = $this->problem($response) ?? ['message' => 'HTTP '.$response->status(), 'retryable' => true];

        return ProviderResult::fail($problem['message'], $problem['retryable'], $request, $this->trim($response->body()));
    }

    /** @return list<string> */
    private function errors(string $xml): array
    {
        $xp = $this->xpath($xml);
        if ($xp === null) {
            return [];
        }
        $out = [];
        foreach ($xp->query("//*[local-name()='Errors']/*[local-name()='Error']") as $e) {
            /** @var DOMElement $e */
            $out[] = trim(($e->getAttribute('Code') !== '' ? $e->getAttribute('Code').' ' : '').($e->getAttribute('ShortText') ?: $e->getAttribute('Details')));
        }

        return $out;
    }

    private function xpath(string $xml): ?DOMXPath
    {
        if (trim($xml) === '') {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument;
        // No network access: the XML comes from outside.
        $ok = $doc->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $ok ? new DOMXPath($doc) : null;
    }

    /** Price of a Total element: integer amount with DecimalPlaces, before tax when given (the PMS adds its own taxes). */
    private function amount(DOMElement $total): string
    {
        $raw = $total->getAttribute('AmountBeforeTax') !== '' ? $total->getAttribute('AmountBeforeTax') : $total->getAttribute('AmountAfterTax');
        $places = (int) ($total->getAttribute('DecimalPlaces') ?: 2);

        return number_format(((float) $raw) / (10 ** $places), $places, '.', '');
    }

    private function minor(string $price, int $decimals): string
    {
        return (string) (int) round(((float) $price) * (10 ** $decimals));
    }

    /** @return array{0: string, 1: int} currency code of the property and its minor units */
    private function currency(ChannelConnection $c): array
    {
        $row = DB::table('properties')->join('currencies', 'currencies.code', '=', 'properties.currency_code')
            ->where('properties.id', $c->property_id)->first(['currencies.code', 'currencies.minor_units']);

        return [(string) ($row->code ?? 'EUR'), (int) ($row->minor_units ?? 2)];
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function trim(string $text): string
    {
        return mb_substr($text, 0, 20000);
    }
}
