<?php

namespace App\Http\Controllers\WebApi\Property;

use App\Domain\Channels\ChannelPresenter;
use App\Domain\Channels\ChannelReservationService;
use App\Domain\Channels\ChannelSyncService;
use App\Domain\Channels\ConnectionService;
use App\Domain\Channels\TestChannelService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Property\Channels\ConnectionRequest;
use App\Http\Requests\Property\Channels\MappingRequest;
use App\Http\Requests\Property\Channels\TestBookingRequest;
use App\Models\ChannelConnection;
use App\Models\ChannelReservation;
use App\Support\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Channel manager actions: connections, mapping, sync, logs, bookings and the Test Channel tools. */
class ChannelsController extends Controller
{
    public function __construct(
        private readonly ConnectionService $connections,
        private readonly ChannelSyncService $sync,
        private readonly ChannelPresenter $presenter,
        private readonly TestChannelService $testChannel,
    ) {}

    public function store(ConnectionRequest $request, PropertyContext $context): JsonResponse
    {
        $c = $this->connections->create($context->property(), $request->validated(), $request->user());

        return response()->json(['message' => __('channels.saved'), 'connection' => $this->presenter->row($c)], 201);
    }

    public function update(ConnectionRequest $request, mixed $property, string $connection): JsonResponse
    {
        $c = $this->connections->update($this->find($connection), $request->validated(), $request->user());

        return response()->json(['message' => __('channels.saved'), 'connection' => $this->presenter->row($c)]);
    }

    public function test(Request $request, mixed $property, string $connection): JsonResponse
    {
        $r = $this->connections->test($this->find($connection), $request->user());

        return response()->json(['ok' => $r->ok, 'message' => $r->ok ? __('channels.messages.tested_ok') : $r->message], $r->ok ? 200 : 422);
    }

    public function sync(Request $request, mixed $property, string $connection): JsonResponse
    {
        $result = $this->sync->sync($this->find($connection), true);
        $message = match ($result['status']) {
            'busy' => __('channels.messages.busy'),
            'failed' => __('channels.messages.sync_failed', ['message' => $result['message']]),
            'nothing' => __('channels.messages.nothing'),
            'unapproved' => __('channels.messages.not_approved'),
            default => __('channels.messages.synced', ['count' => $result['updates']]),
        };

        return response()->json(['message' => $message, 'status' => $result['status']], in_array($result['status'], ['failed', 'unapproved'], true) ? 422 : 200);
    }

    public function pause(Request $request, mixed $property, string $connection): JsonResponse
    {
        $this->connections->pause($this->find($connection), $request->user());

        return response()->json(['message' => __('channels.messages.paused')]);
    }

    public function resume(Request $request, mixed $property, string $connection): JsonResponse
    {
        $this->connections->resume($this->find($connection), $request->user());

        return response()->json(['message' => __('channels.messages.resumed')]);
    }

    public function requestApproval(Request $request, mixed $property, string $connection): JsonResponse
    {
        $c = $this->find($connection);
        if ($c->approval_status !== 'rejected') {
            throw ValidationException::withMessages(['connection' => __('channels.messages.cannot_request')]);
        }
        $this->connections->requestApproval($c, $request->user());

        return response()->json(['message' => __('channels.messages.approval_requested')]);
    }

    public function suspend(Request $request, mixed $property, string $connection): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $this->connections->suspend($this->find($connection), $request->user(), $data['reason'] ?? null);

        return response()->json(['message' => __('channels.messages.suspended')]);
    }

    public function unsuspend(Request $request, mixed $property, string $connection): JsonResponse
    {
        $this->connections->unsuspend($this->find($connection), $request->user());

        return response()->json(['message' => __('channels.messages.unsuspended')]);
    }

    public function disconnect(Request $request, mixed $property, string $connection): JsonResponse
    {
        $this->connections->disconnect($this->find($connection), $request->user());

        return response()->json(['message' => __('channels.messages.disconnected')]);
    }

    public function suggest(mixed $property, string $connection): JsonResponse
    {
        $s = $this->connections->suggestions($this->find($connection));

        return response()->json(['rooms' => $s['rooms'], 'rates' => $s['rates'], 'message' => __('channels.messages.suggested', ['count' => count($s['rooms']) + count($s['rates'])])]);
    }

    public function mapping(MappingRequest $request, mixed $property, string $connection): JsonResponse
    {
        $c = $this->find($connection);
        $this->connections->saveMappings($c, $request->validated('rooms'), $request->validated('rates'), $request->user());

        return response()->json(['message' => __('channels.messages.mapping_saved'), 'connection' => $this->presenter->row($c->fresh())]);
    }

    public function logs(Request $request, mixed $property, string $connection): JsonResponse
    {
        return response()->json($this->presenter->logs($this->find($connection), $request));
    }

    public function bookings(Request $request, mixed $property, string $connection): JsonResponse
    {
        return response()->json($this->presenter->bookings($this->find($connection), $request));
    }

    public function retryBooking(mixed $property, string $connection, int $booking, ChannelReservationService $inbound): JsonResponse
    {
        $c = $this->find($connection);
        $record = ChannelReservation::query()->where('connection_id', $c->id)->findOrFail($booking);
        $r = $inbound->retry($record);
        if ($r['status'] === 'failed') {
            return response()->json(['message' => __('channels.messages.import_failed', ['message' => $r['message']])], 422);
        }

        return response()->json(['message' => __('channels.messages.imported', ['ref' => $r['reservation']?->booking_ref])]);
    }

    // --- Test Channel -------------------------------------------------------------------

    public function holds(mixed $property, string $connection): JsonResponse
    {
        return response()->json(['holds' => $this->testChannel->holds($this->find($connection))]);
    }

    public function sendBooking(TestBookingRequest $request, mixed $property, string $connection, PropertyContext $context): JsonResponse
    {
        $c = $this->find($connection);
        $message = $this->testChannel->newMessage($request->validated() + ['currency' => $context->property()->currency_code]);

        return $this->imported($this->testChannel->send($c, $message));
    }

    public function modifyBooking(Request $request, mixed $property, string $connection, int $booking): JsonResponse
    {
        $data = $request->validate(['check_in' => ['required', 'date_format:Y-m-d'], 'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in']]);
        $c = $this->find($connection);

        return $this->imported($this->testChannel->modify($c, $this->record($c, $booking), $data['check_in'], $data['check_out']));
    }

    public function cancelBooking(mixed $property, string $connection, int $booking): JsonResponse
    {
        $c = $this->find($connection);

        return $this->imported($this->testChannel->cancel($c, $this->record($c, $booking)));
    }

    public function outage(mixed $property, string $connection): JsonResponse
    {
        $this->testChannel->simulateOutage($this->find($connection));

        return response()->json(['message' => __('channels.test_channel.outage_on')]);
    }

    private function imported(array $result): JsonResponse
    {
        if (($result['status'] ?? 'failed') === 'failed') {
            throw ValidationException::withMessages(['booking' => __('channels.messages.import_failed', ['message' => $result['message'] ?? ''])]);
        }

        return response()->json(['message' => __('channels.messages.imported', ['ref' => $result['reservation']?->booking_ref ?? '']), 'status' => $result['status']]);
    }

    private function record(ChannelConnection $c, int $id): ChannelReservation
    {
        return ChannelReservation::query()->where('connection_id', $c->id)->findOrFail($id);
    }

    private function find(string $id): ChannelConnection
    {
        return ChannelConnection::query()->where('public_id', $id)->firstOrFail();
    }
}
