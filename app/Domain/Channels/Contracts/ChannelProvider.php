<?php

namespace App\Domain\Channels\Contracts;

use App\Domain\Channels\Data\InboundReservation;
use App\Domain\Channels\Data\ProviderResult;
use App\Models\ChannelConnection;
use Illuminate\Http\Request;

/**
 * One sales channel (OTA). The channel manager only talks to channels through this contract, so
 * adding Booking.com, Expedia, Agoda … means one new class registered in config/channels.php.
 *
 * Values sent are always the current state (never increments), so a repeated message is harmless.
 */
interface ChannelProvider
{
    /** Credential fields the hotel enters: [['key' => 'api_key', 'type' => 'secret'|'text', 'required' => bool]]. */
    public function credentialFields(): array;

    /** Checks the hotel id and credentials with the channel. */
    public function testConnection(ChannelConnection $connection): ProviderResult;

    /**
     * Rooms and rates of the hotel on the channel, for the mapping screen:
     * ['rooms' => [['id' => .., 'name' => ..]], 'rates' => [['id' => .., 'name' => .., 'room_id' => ..]]].
     */
    public function listings(ChannelConnection $connection): array;

    /**
     * Sends availability, prices and restrictions.
     *
     * @param  list<\App\Domain\Channels\Data\AriUpdate>  $updates
     */
    public function pushAri(ChannelConnection $connection, array $updates): ProviderResult;

    /**
     * Bookings / changes / cancellations pushed by the channel to our webhook. Must verify the request.
     *
     * @return list<InboundReservation>
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException 401 when the request is not authentic
     */
    public function parseWebhook(ChannelConnection $connection, Request $request): array;

    /**
     * Bookings waiting on the channel (for channels that are polled instead of pushing).
     *
     * @return list<InboundReservation>
     */
    public function pullReservations(ChannelConnection $connection): array;

    /** Tells the channel a booking was taken over (or failed), when the channel expects it. */
    public function acknowledge(ChannelConnection $connection, InboundReservation $booking, bool $ok, ?string $pmsRef, ?string $error): void;
}
