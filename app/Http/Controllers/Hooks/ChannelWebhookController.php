<?php

namespace App\Http\Controllers\Hooks;

use App\Domain\Channels\ChannelRegistry;
use App\Domain\Channels\ChannelReservationService;
use App\Http\Controllers\Controller;
use App\Models\ChannelConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /hooks/channels/{provider}/{connection}: bookings pushed by a channel. The provider adapter
 * verifies the request (signature with the connection's webhook secret); each booking goes through
 * ChannelReservationService. Paused / disconnected connections refuse bookings with 409 so the
 * channel keeps them queued.
 */
class ChannelWebhookController extends Controller
{
    public function __invoke(Request $request, ChannelRegistry $registry, ChannelReservationService $service, string $provider, string $connection): JsonResponse
    {
        $c = ChannelConnection::acrossProperties()->where('public_id', $connection)->where('provider', $provider)->first();
        abort_if($c === null || ! $registry->available($provider), 404);
        // Unapproved or suspended connections receive nothing (checked before the body is read).
        abort_unless($c->isApproved(), 403, 'Connection is not approved');
        $bookings = $registry->provider($c)->parseWebhook($c, $request);
        abort_unless(in_array($c->status, ['active', 'error'], true), 409, 'Connection is not active');

        $results = [];
        foreach ($bookings as $b) {
            $r = $service->ingest($c, $b);
            $results[] = ['external_ref' => $b->externalRef, 'status' => $r['status'], 'booking_ref' => $r['reservation']?->booking_ref, 'error' => $r['message']];
        }

        return response()->json(['results' => $results]);
    }
}
