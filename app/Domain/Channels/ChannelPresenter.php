<?php

namespace App\Domain\Channels;

use App\Models\ChannelConnection;
use App\Models\ChannelReservation;
use App\Models\ChannelSyncLog;
use App\Models\Property;
use App\Support\Listing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Data of the Channel Manager pages (list, one connection with mapping / log / bookings). */
class ChannelPresenter
{
    public function __construct(private readonly ChannelRegistry $registry) {}

    public function index(Property $property): array
    {
        $connections = ChannelConnection::query()->orderBy('id')->get();
        $ids = $connections->pluck('id');
        $rooms = DB::table('channel_room_mappings')->whereIn('connection_id', $ids)->select('connection_id', DB::raw('count(*) n'))->groupBy('connection_id')->pluck('n', 'connection_id');
        $rates = DB::table('channel_rate_plan_mappings')->whereIn('connection_id', $ids)->select('connection_id', DB::raw('count(*) n'))->groupBy('connection_id')->pluck('n', 'connection_id');
        $bookings = DB::table('channel_reservations')->whereIn('connection_id', $ids)->where('created_at', '>=', now()->subDays(30))->count();
        $maxLog = (int) DB::table('ari_change_log')->where('property_id', $property->id)->max('id');

        $rows = $connections->map(fn (ChannelConnection $c) => $this->row($c, (int) ($rooms[$c->id] ?? 0), (int) ($rates[$c->id] ?? 0), $maxLog))->values();
        $connected = $connections->pluck('provider')->all();

        return [
            'connections' => $rows,
            'providers' => collect($this->registry->all())->map(fn ($p) => $p + ['connected' => in_array($p['key'], $connected, true)])->values(),
            'credential_fields' => collect($this->registry->all())->filter(fn ($p) => $p['available'])
                ->map(fn ($p) => $this->registry->provider($p['key'])->credentialFields())->all(),
            'kpi' => [
                'connected' => $connections->where('approval_status', 'approved')->whereIn('status', ['active'])->count(),
                'last_sync' => $connections->max('last_success_at')?->toIso8601String(),
                'bookings' => $bookings,
                'attention' => $connections->whereIn('status', ['error'])->count() + $connections->where('status', 'active')->where('failures', '>', 0)->count(),
            ],
        ];
    }

    public function row(ChannelConnection $c, ?int $rooms = null, ?int $rates = null, ?int $maxLog = null): array
    {
        $maxLog ??= (int) DB::table('ari_change_log')->where('property_id', $c->property_id)->max('id');

        return [
            'id' => $c->public_id, 'provider' => $c->provider, 'name' => $c->name, 'hotel_id' => $c->external_hotel_id, 'status' => $c->status,
            'approval' => $c->approval_status, 'approval_note' => $c->approval_note, 'requires_approval' => $this->registry->requiresApproval($c->provider),
            'rooms' => $rooms ?? $c->roomMappings()->count(), 'rates' => $rates ?? $c->rateMappings()->count(),
            'last_success_at' => $c->last_success_at?->toIso8601String(), 'last_error' => $c->last_error, 'last_error_at' => $c->last_error_at?->toIso8601String(),
            'failures' => (int) $c->failures, 'next_attempt_at' => $c->next_attempt_at?->toIso8601String(),
            'waiting' => $c->isSyncing() && $maxLog > (int) $c->last_ari_log_id,
        ];
    }

    /** One connection: header, overview data, mapping data (rooms, products, saved mapping). */
    public function show(ChannelConnection $c, bool $withListings = true): array
    {
        $roomTypes = DB::table('room_types')->where('property_id', $c->property_id)->whereNull('deleted_at')->orderBy('sort_order')->orderBy('name')->get(['id', 'public_id', 'code', 'name']);
        $savedRooms = DB::table('channel_room_mappings')->where('connection_id', $c->id)->pluck('external_room_id', 'room_type_id');
        $savedRates = DB::table('channel_rate_plan_mappings')->where('connection_id', $c->id)->get()->keyBy('product_id');
        $products = DB::table('room_type_rate_plans as p')->join('rate_plans as rp', 'rp.id', '=', 'p.rate_plan_id')
            ->where('p.property_id', $c->property_id)->orderBy('rp.sort_order')->orderBy('rp.name')
            ->get(['p.id', 'p.room_type_id', 'rp.name', 'rp.code', 'rp.sell_on_channels', 'p.is_active']);

        $mapping = $roomTypes->map(fn ($rt) => [
            'room_type_id' => $rt->public_id, 'code' => $rt->code, 'name' => $rt->name, 'external_room_id' => $savedRooms[$rt->id] ?? '',
            'rates' => $products->where('room_type_id', $rt->id)->map(fn ($p) => [
                'product_id' => (int) $p->id, 'name' => $p->name, 'code' => $p->code, 'sellable' => (bool) $p->sell_on_channels && (bool) $p->is_active,
                'external_rate_id' => $savedRates[$p->id]->external_rate_id ?? '', 'markup_type' => $savedRates[$p->id]->markup_type ?? 'none',
                'markup_value' => isset($savedRates[$p->id]) && $savedRates[$p->id]->markup_value !== null ? rtrim(rtrim((string) $savedRates[$p->id]->markup_value, '0'), '.') : '',
            ])->values(),
        ])->values();

        $listings = ['rooms' => [], 'rates' => []];
        if ($withListings) {
            try {
                $listings = $this->registry->provider($c)->listings($c);
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $creds = $c->credentials ?? [];
        // Real channels: only E2X staff see and enter the credentials.
        $user = auth()->user();
        $staff = $user !== null && app(\App\Domain\Access\AccessService::class)->allows($user, 'platform.channels.approve');
        $editable = $staff || ! $this->registry->requiresApproval($c->provider);

        return [
            'connection' => $this->row($c) + [
                'webhook_url' => route('hooks.channels', ['provider' => $c->provider, 'connection' => $c->public_id]),
                'webhook_secret' => $creds['webhook_secret'] ?? null,
                'last_full_sync_at' => $c->last_full_sync_at?->toIso8601String(),
                'sync_days' => (int) config('channels.sync_days', 365),
                'is_staff' => $staff,
                'can_edit_credentials' => $editable,
                'credential_fields' => ! $editable ? [] : array_map(fn ($f) => $f + ['has_value' => ! empty($creds[$f['key']]), 'value' => $f['type'] === 'secret' ? '' : (string) ($creds[$f['key']] ?? '')], $this->registry->provider($c)->credentialFields()),
            ],
            'mapping' => $mapping,
            'listings' => $listings,
            'is_test' => $c->provider === 'test',
        ];
    }

    public function logs(ChannelConnection $c, Request $request): array
    {
        $q = ChannelSyncLog::query()->where('connection_id', $c->id)->orderByDesc('id')
            ->when(in_array($request->query('direction'), ['outbound', 'inbound'], true), fn ($q) => $q->where('direction', $request->query('direction')))
            ->when(in_array($request->query('status'), ['success', 'failed', 'retrying'], true), fn ($q) => $q->where('status', $request->query('status')));

        return Listing::paginate($q, $request, fn (ChannelSyncLog $l) => [
            'id' => $l->id, 'time' => $l->created_at?->toIso8601String(), 'direction' => $l->direction, 'type' => $l->message_type, 'status' => $l->status,
            'summary' => $l->summary ? json_decode($l->summary, true) : null, 'items' => (int) $l->items, 'attempts' => (int) $l->attempts, 'error' => $l->error,
            'request' => $l->request_body, 'response' => $l->response_body,
        ]);
    }

    public function bookings(ChannelConnection $c, Request $request): array
    {
        $q = ChannelReservation::query()->where('connection_id', $c->id)->with('reservation:id,booking_ref,public_id,guest_name')->orderByDesc('updated_at');

        return Listing::paginate($q, $request, function (ChannelReservation $r) {
            $p = (array) $r->payload;
            $room = (array) (($p['rooms'][0] ?? []));

            return [
                'id' => $r->id, 'external_ref' => $r->external_ref, 'status' => $r->status, 'version' => (int) $r->version, 'error' => $r->error,
                'booking_ref' => $r->reservation?->booking_ref, 'booking_id' => $r->reservation?->public_id,
                'guest' => $r->reservation?->guest_name ?? trim(($p['guest']['first_name'] ?? '').' '.($p['guest']['last_name'] ?? '')),
                'check_in' => $room['check_in'] ?? null, 'check_out' => $room['check_out'] ?? null, 'updated_at' => $r->updated_at?->toIso8601String(),
                'can_retry' => $r->status === 'failed',
            ];
        });
    }
}
