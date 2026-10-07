<?php

namespace App\Domain\Channels;

use App\Domain\Accommodation\InProperty;
use App\Domain\Channels\Data\InboundReservation;
use App\Domain\Channels\Data\ProviderResult;
use App\Domain\Reservations\ReservationService;
use App\Models\BookingSource;
use App\Models\ChannelConnection;
use App\Models\ChannelReservation;
use App\Models\Product;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Inbound sync: bookings, changes and cancellations from a channel become PMS reservations through
 * ReservationService (same inventory, taxes, folio, events as every other booking).
 *
 *  - Idempotent: one channel_reservations row per channel booking reference; a message with the same
 *    or an older version is ignored; the PMS booking is created with an idempotency key.
 *  - The channel already sold the stay, so restrictions are not checked again; inventory is (an
 *    overbooking is refused and shown as a failed import to resolve, never silently dropped).
 *  - Prices: the channel's price per night (taxes added by the PMS tax rules).
 *  - Cancellations from the channel never charge a PMS cancellation fee (the channel handles it).
 */
class ChannelReservationService
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly ChannelRegistry $registry,
        private readonly ChannelSyncService $sync,
    ) {}

    /** @return array{status: string, reservation: ?Reservation, message: ?string} */
    public function ingest(ChannelConnection $connection, InboundReservation $in): array
    {
        if ($in->externalRef === '' || ! in_array($in->type, ['new', 'modify', 'cancel'], true)) {
            return $this->fail($connection, null, $in, __('channels.errors.bad_message'));
        }
        $record = ChannelReservation::query()->where('connection_id', $connection->id)->where('external_ref', $in->externalRef)->first();
        if ($record !== null && $record->status !== 'failed' && $record->version >= $in->version && $in->type !== 'cancel') {
            $this->sync->log($connection, 'inbound', 'reservation_duplicate', ProviderResult::ok(json_encode(['ref' => $in->externalRef]), json_encode($in->toArray())), 1, json_encode(['ref' => $in->externalRef, 'version' => $in->version]));

            return ['status' => 'duplicate', 'reservation' => $record->reservation, 'message' => null];
        }
        $property = Property::query()->withoutGlobalScopes()->findOrFail($connection->property_id);

        try {
            $reservation = InProperty::run($property, function () use ($connection, $property, $in, $record) {
                $existing = $record?->reservation_id ? Reservation::query()->find($record->reservation_id) : null;
                if ($in->type === 'cancel') {
                    if ($existing === null) {
                        throw ValidationException::withMessages(['ref' => __('channels.errors.unknown_booking')]);
                    }
                    if ($existing->status !== 'cancelled') {
                        $existing = $this->reservations->cancel($existing, __('channels.cancelled_on', ['channel' => $this->label($connection)]), null, true);
                    }

                    return $existing;
                }
                $data = $this->bookingData($connection, $property, $in);
                if ($existing !== null) {
                    $rooms = ReservationRoom::query()->where('reservation_id', $existing->id)->whereNotIn('status', ['cancelled', 'no_show'])->orderBy('sort_order')->orderBy('id')->pluck('id')->all();
                    foreach ($data['rooms'] as $i => &$room) {
                        if (isset($rooms[$i])) {
                            $room['id'] = (int) $rooms[$i];
                        }
                    }
                    unset($room);

                    return $this->reservations->modify($existing, array_diff_key($data, ['idempotency_key' => 1, 'status' => 1]), null);
                }

                return $this->reservations->create($property, $data, null);
            });
        } catch (Throwable $e) {
            if (! $e instanceof ValidationException && ! $e instanceof \App\Domain\Inventory\Exceptions\NotAvailableException) {
                report($e);
            }
            $message = $e instanceof ValidationException ? collect($e->errors())->flatten()->implode(' ') : $e->getMessage();

            return $this->fail($connection, $record, $in, $message);
        }

        $status = $in->type === 'cancel' ? 'cancelled' : ($record !== null && $record->reservation_id ? 'modified' : 'new');
        $values = ['reservation_id' => $reservation->id, 'version' => max($in->version, (int) ($record->version ?? 0)), 'status' => $status, 'payload' => $in->toArray(), 'error' => null];
        $record === null
            ? ChannelReservation::query()->create(['connection_id' => $connection->id, 'external_ref' => $in->externalRef] + $values)
            : $record->forceFill($values)->save();
        $this->sync->log($connection, 'inbound', 'reservation_'.$in->type, ProviderResult::ok(null, json_encode($in->toArray()), json_encode(['booking_ref' => $reservation->booking_ref])), count($in->rooms),
            json_encode(['ref' => $in->externalRef, 'booking' => $reservation->booking_ref, 'version' => $in->version]));
        $this->acknowledge($connection, $in, true, $reservation->booking_ref, null);

        return ['status' => $status, 'reservation' => $reservation, 'message' => null];
    }

    /** Imports a failed booking again (after the hotel fixed mapping or availability). */
    public function retry(ChannelReservation $record): array
    {
        $connection = ChannelConnection::acrossProperties()->findOrFail($record->connection_id);

        return $this->ingest($connection, InboundReservation::fromArray((array) $record->payload));
    }

    private function bookingData(ChannelConnection $connection, Property $property, InboundReservation $in): array
    {
        if ($in->rooms === []) {
            throw ValidationException::withMessages(['rooms' => __('channels.errors.no_rooms')]);
        }
        if ($in->currency !== null && $in->currency !== (string) $property->currency_code) {
            throw ValidationException::withMessages(['currency' => __('channels.errors.currency', ['given' => $in->currency, 'expected' => $property->currency_code])]);
        }
        $roomMap = DB::table('channel_room_mappings')->where('connection_id', $connection->id)->pluck('room_type_id', 'external_room_id');
        $rateMap = DB::table('channel_rate_plan_mappings')->where('connection_id', $connection->id)->pluck('product_id', 'external_rate_id');
        $rooms = [];
        foreach ($in->rooms as $i => $r) {
            $roomTypeId = $roomMap[(string) ($r['room_id'] ?? '')] ?? null;
            if ($roomTypeId === null) {
                throw ValidationException::withMessages(["rooms.$i" => __('channels.errors.room_unmapped', ['room' => (string) ($r['room_id'] ?? '?')])]);
            }
            $productId = isset($r['rate_id']) ? ($rateMap[(string) $r['rate_id']] ?? null) : null;
            // A rate the hotel did not map: the room type's first mapped rate.
            $productId ??= Product::query()->where('room_type_id', $roomTypeId)->whereIn('id', $rateMap->values())->orderBy('id')->value('id');
            if ($productId === null) {
                throw ValidationException::withMessages(["rooms.$i" => __('channels.errors.rate_unmapped', ['rate' => (string) ($r['rate_id'] ?? '?')])]);
            }
            $nightly = [];
            foreach ((array) ($r['nightly'] ?? []) as $date => $price) {
                $nightly[CarbonImmutable::parse((string) $date)->toDateString()] = (string) $price;
            }
            $rooms[] = [
                'product' => Product::query()->findOrFail($productId),
                'check_in' => CarbonImmutable::parse((string) $r['check_in']), 'check_out' => CarbonImmutable::parse((string) $r['check_out']),
                'adults' => max(1, (int) ($r['adults'] ?? 1)), 'children' => (int) ($r['children'] ?? 0), 'infants' => (int) ($r['infants'] ?? 0),
                'nightly' => $nightly ?: null,
                'rate' => $nightly === [] && isset($r['price']) ? (string) $r['price'] : null,
            ];
        }
        $g = $in->guest;

        return [
            'status' => 'confirmed',
            'source_id' => BookingSource::query()->whereNull('property_id')->where('code', 'ota')->value('id'),
            'channel_ref' => mb_substr($this->label($connection).' '.$in->externalRef, 0, 60),
            'idempotency_key' => mb_substr('ch'.$connection->id.'-'.$in->externalRef, 0, 64),
            'channel' => 'ota', 'skip_restrictions' => true, 'allow_past' => true,
            'special_requests' => $in->notes ? mb_substr($in->notes, 0, 1000) : null,
            'guest' => array_filter([
                'first_name' => (string) ($g['first_name'] ?? '') ?: __('channels.guest_unknown'),
                'last_name' => $g['last_name'] ?? null, 'email' => $g['email'] ?? null, 'phone' => $g['phone'] ?? null,
                'country_iso2' => isset($g['country']) ? strtoupper((string) $g['country']) : null,
            ], fn ($v) => $v !== null && $v !== ''),
            'rooms' => $rooms,
        ];
    }

    private function fail(ChannelConnection $connection, ?ChannelReservation $record, InboundReservation $in, string $message): array
    {
        if ($in->externalRef !== '') {
            $values = ['status' => 'failed', 'payload' => $in->toArray(), 'version' => $in->version, 'error' => mb_substr($message, 0, 1000)];
            if ($record === null) {
                ChannelReservation::query()->create(['connection_id' => $connection->id, 'external_ref' => $in->externalRef] + $values);
            } elseif ($record->reservation_id === null || $in->type === 'new') {
                $record->forceFill($values)->save();
            } else {
                // A failed change of an imported booking: keep the link, show the error.
                $record->forceFill(['error' => mb_substr($message, 0, 1000), 'payload' => $in->toArray()])->save();
            }
        }
        $this->sync->log($connection, 'inbound', 'reservation_'.$in->type, ProviderResult::fail($message, false, json_encode($in->toArray())), count($in->rooms), json_encode(['ref' => $in->externalRef, 'version' => $in->version]));
        $this->acknowledge($connection, $in, false, null, $message);

        return ['status' => 'failed', 'reservation' => null, 'message' => $message];
    }

    private function acknowledge(ChannelConnection $connection, InboundReservation $in, bool $ok, ?string $ref, ?string $error): void
    {
        try {
            $this->registry->provider($connection)->acknowledge($connection, $in, $ok, $ref, $error);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function label(ChannelConnection $connection): string
    {
        return $connection->name ?: __('channels.providers.'.$connection->provider);
    }
}
