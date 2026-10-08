<?php

namespace App\Domain\Channels;

use App\Domain\Accommodation\InProperty;
use App\Domain\Access\AccessService;
use App\Domain\Audit\AuditLogger;
use App\Domain\Platform\ApprovalService;
use App\Infrastructure\Database\Tx;
use App\Models\ChannelConnection;
use App\Models\ChannelRateMapping;
use App\Models\ChannelRoomMapping;
use App\Models\Product;
use App\Models\Property;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Connections of a property to channels: add (credentials encrypted, webhook secret generated),
 * edit, test, pause / resume, disconnect, and the room / rate mappings. After a mapping change
 * or a resume the connection gets a full sync, so the channel always holds the PMS values.
 */
class ConnectionService
{
    public function __construct(
        private readonly ChannelRegistry $registry,
        private readonly ChannelSyncService $sync,
        private readonly AuditLogger $audit,
        private readonly AccessService $access,
        private readonly ApprovalService $approvals,
    ) {}

    /** May this user enter credentials and approve channel connections (E2X staff)? */
    public function isStaff(?User $by): bool
    {
        return $by !== null && $this->access->allows($by, 'platform.channels.approve');
    }

    /** Real channels: only E2X staff enter credentials and change the hotel id. The Test Channel is open to the hotel. */
    private function hotelMayEditCredentials(string $provider, ?User $by): bool
    {
        return ! $this->registry->requiresApproval($provider) || $this->isStaff($by);
    }

    public function create(Property $property, array $data, ?User $by = null): ChannelConnection
    {
        $provider = (string) $data['provider'];
        if (! $this->registry->available($provider)) {
            throw ValidationException::withMessages(['provider' => __('channels.errors.provider_unavailable')]);
        }
        if (ChannelConnection::acrossProperties()->where('property_id', $property->id)->where('provider', $provider)->exists()) {
            throw ValidationException::withMessages(['provider' => __('channels.errors.provider_exists')]);
        }
        $mayEnter = $this->hotelMayEditCredentials($provider, $by);
        $credentials = $mayEnter ? $this->credentials($provider, (array) ($data['credentials'] ?? []), []) : [];
        $approved = ! $this->registry->requiresApproval($provider) || $this->isStaff($by);
        $connection = InProperty::run($property, fn () => ChannelConnection::query()->create([
            'property_id' => $property->id, 'provider' => $provider, 'name' => trim((string) ($data['name'] ?? '')) ?: null,
            'external_hotel_id' => trim((string) $data['external_hotel_id']), 'status' => 'pending',
            'approval_status' => $approved ? 'approved' : 'pending',
            'approved_by' => $approved && $by !== null ? $by->id : null, 'approved_at' => $approved ? now() : null,
            'credentials' => $credentials + ['webhook_secret' => Str::random(48)],
            // Start after the current change log: history is covered by the first full sync.
            'last_ari_log_id' => (int) DB::table('ari_change_log')->where('property_id', $property->id)->max('id'),
            'settings' => [],
        ]));
        $this->audit->log('channel.connected', $connection, ['after' => ['provider' => $provider, 'hotel' => $connection->external_hotel_id]], $property->id, $by?->id);
        if ($approved) {
            $this->test($connection, $by);
        } else {
            $this->requestApproval($connection, $by);
        }

        return $connection->fresh();
    }

    /** The hotel asks E2X to connect this channel (again, after a rejection). */
    public function requestApproval(ChannelConnection $connection, ?User $by = null): void
    {
        if ($connection->approval_status === 'rejected') {
            $connection->forceFill(['approval_status' => 'pending', 'approval_note' => null])->save();
        }
        $property = Property::query()->withoutGlobalScopes()->find($connection->property_id);
        $this->approvals->request('channel_connection', $connection, $by,
            __('approvals.channel_summary', ['channel' => __('channels.providers.'.$connection->provider), 'property' => ($property?->name ?? '').' ('.($property?->code ?? '').')']),
            $connection->property_id, ['connection' => $connection->public_id, 'provider' => $connection->provider, 'property_code' => $property?->code]);
    }

    /** E2X stops all traffic of a connection at any time; the hotel's settings and mapping stay as they are. */
    public function suspend(ChannelConnection $connection, User $by, ?string $reason = null): void
    {
        $before = $connection->approval_status;
        $connection->forceFill(['approval_status' => 'suspended', 'approval_note' => $reason !== null ? mb_substr(trim($reason), 0, 500) : null])->save();
        $this->audit->log('channel.suspended', $connection, ['before' => ['approval' => $before], 'after' => ['approval' => 'suspended'], 'reason' => $reason], $connection->property_id, $by->id);
    }

    public function unsuspend(ChannelConnection $connection, User $by): void
    {
        $connection->forceFill(['approval_status' => 'approved', 'approval_note' => null])->save();
        $this->audit->log('channel.unsuspended', $connection, ['after' => ['approval' => 'approved']], $connection->property_id, $by->id);
        if ($connection->isSyncing()) {
            $this->sync->sync($connection->fresh(), true);
        }
    }

    public function update(ChannelConnection $connection, array $data, ?User $by = null): ChannelConnection
    {
        $before = ['name' => $connection->name, 'hotel' => $connection->external_hotel_id];
        if ($this->hotelMayEditCredentials($connection->provider, $by)) {
            $connection->forceFill([
                'name' => trim((string) ($data['name'] ?? '')) ?: null,
                'external_hotel_id' => trim((string) ($data['external_hotel_id'] ?? $connection->external_hotel_id)),
                'credentials' => $this->credentials($connection->provider, (array) ($data['credentials'] ?? []), (array) ($connection->credentials ?? [])),
            ])->save();
        } else {
            $hotel = trim((string) ($data['external_hotel_id'] ?? $connection->external_hotel_id));
            if ($hotel !== $connection->external_hotel_id) {
                throw ValidationException::withMessages(['external_hotel_id' => __('channels.errors.staff_only')]);
            }
            $connection->forceFill(['name' => trim((string) ($data['name'] ?? '')) ?: null])->save();
        }
        $this->audit->log('channel.updated', $connection, ['before' => $before, 'after' => ['name' => $connection->name, 'hotel' => $connection->external_hotel_id]], $connection->property_id, $by?->id);
        if ($connection->isApproved() && in_array($connection->status, ['pending', 'error'], true)) {
            $this->test($connection, $by);
        }

        return $connection->fresh();
    }

    /** Checks the credentials; a working pending / error connection becomes active. */
    public function test(ChannelConnection $connection, ?User $by = null): \App\Domain\Channels\Data\ProviderResult
    {
        $result = $this->registry->provider($connection)->testConnection($connection);
        $this->sync->log($connection, 'outbound', 'connection_test', $result, 0, null);
        // A connection only goes live once it is approved.
        if ($result->ok && $connection->isApproved() && in_array($connection->status, ['pending', 'error'], true)) {
            $connection->forceFill(['status' => 'active', 'failures' => 0, 'last_error' => null, 'next_attempt_at' => null])->save();
        } elseif (! $result->ok) {
            $connection->forceFill(['last_error' => mb_substr((string) $result->message, 0, 1000), 'last_error_at' => now()])->save();
        }

        return $result;
    }

    public function pause(ChannelConnection $connection, ?User $by = null): void
    {
        $this->status($connection, 'paused', 'channel.paused', $by);
    }

    public function resume(ChannelConnection $connection, ?User $by = null): void
    {
        if (! $connection->isApproved()) {
            throw ValidationException::withMessages(['connection' => __('channels.messages.not_approved')]);
        }
        $this->status($connection, 'active', 'channel.resumed', $by);
        $connection->forceFill(['failures' => 0, 'next_attempt_at' => null])->save();
        $this->sync->sync($connection, true);
    }

    public function disconnect(ChannelConnection $connection, ?User $by = null): void
    {
        $this->status($connection, 'disconnected', 'channel.disconnected', $by);
    }

    /**
     * Saves all mappings of the connection.
     *
     * @param  array<string, ?string>  $rooms  room type public id => channel room id (null / '' = not mapped)
     * @param  array<int, array{product_id: int, external_rate_id: ?string, markup_type: string, markup_value: ?string}>  $rates
     */
    public function saveMappings(ChannelConnection $connection, array $rooms, array $rates, ?User $by = null): void
    {
        $roomTypes = RoomType::acrossProperties()->where('property_id', $connection->property_id)->whereIn('public_id', array_keys($rooms))->pluck('id', 'public_id');
        $roomRows = [];
        foreach ($rooms as $publicId => $ext) {
            $ext = trim((string) $ext);
            if ($ext !== '' && isset($roomTypes[$publicId])) {
                $roomRows[(int) $roomTypes[$publicId]] = $ext;
            }
        }
        if (count($roomRows) !== count(array_unique($roomRows))) {
            throw ValidationException::withMessages(['rooms' => __('channels.errors.room_twice')]);
        }
        $products = Product::acrossProperties()->where('property_id', $connection->property_id)->whereIn('id', array_column($rates, 'product_id'))->get()->keyBy('id');
        $rateRows = [];
        foreach ($rates as $i => $r) {
            $ext = trim((string) ($r['external_rate_id'] ?? ''));
            $product = $products[(int) $r['product_id']] ?? null;
            if ($ext === '' || $product === null) {
                continue;
            }
            if (! isset($roomRows[(int) $product->room_type_id])) {
                throw ValidationException::withMessages(["rates.$i.external_rate_id" => __('channels.errors.room_not_mapped')]);
            }
            $type = in_array($r['markup_type'] ?? 'none', ChannelRateMapping::MARKUPS, true) ? $r['markup_type'] : 'none';
            $rateRows[(int) $product->id] = ['external_rate_id' => $ext, 'markup_type' => $type, 'markup_value' => $type === 'none' ? null : (string) ($r['markup_value'] ?? '0')];
        }
        if (count($rateRows) !== count(array_unique(array_column($rateRows, 'external_rate_id')))) {
            throw ValidationException::withMessages(['rates' => __('channels.errors.rate_twice')]);
        }

        Tx::run(function () use ($connection, $roomRows, $rateRows) {
            ChannelRateMapping::query()->where('connection_id', $connection->id)->delete();
            ChannelRoomMapping::query()->where('connection_id', $connection->id)->delete();
            foreach ($roomRows as $rtId => $ext) {
                ChannelRoomMapping::query()->create(['connection_id' => $connection->id, 'room_type_id' => $rtId, 'external_room_id' => $ext]);
            }
            foreach ($rateRows as $pid => $row) {
                ChannelRateMapping::query()->create(['connection_id' => $connection->id, 'product_id' => $pid] + $row);
            }
        });
        $this->audit->log('channel.mapping_saved', $connection, ['after' => ['rooms' => count($roomRows), 'rates' => count($rateRows)]], $connection->property_id, $by?->id);
        if ($connection->isSyncing()) {
            $this->sync->sync($connection, true);
        }
    }

    /** Suggested mapping: channel rooms / rates matched to PMS room types / products by code or name. */
    public function suggestions(ChannelConnection $connection): array
    {
        $listings = $this->registry->provider($connection)->listings($connection);
        $norm = fn (string $s) => Str::of($s)->lower()->replaceMatches('/[^a-z0-9]+/', '')->toString();
        $rooms = [];
        foreach (RoomType::acrossProperties()->where('property_id', $connection->property_id)->get(['id', 'public_id', 'code', 'name']) as $rt) {
            foreach ($listings['rooms'] ?? [] as $l) {
                $id = $norm((string) $l['id']);
                if ($norm((string) $l['name']) === $norm($rt->name) || str_ends_with($id, $norm($rt->code))) {
                    $rooms[$rt->public_id] = (string) $l['id'];
                    break;
                }
            }
        }
        $rates = [];
        $products = DB::table('room_type_rate_plans as p')->join('rate_plans as rp', 'rp.id', '=', 'p.rate_plan_id')->join('room_types as rt', 'rt.id', '=', 'p.room_type_id')
            ->where('p.property_id', $connection->property_id)->get(['p.id', 'rt.public_id as rt_public', 'rt.code as rt_code', 'rp.code as rp_code']);
        foreach ($products as $p) {
            $roomExt = $rooms[$p->rt_public] ?? null;
            foreach ($listings['rates'] ?? [] as $l) {
                if ($roomExt !== null && (string) ($l['room_id'] ?? '') === $roomExt && str_ends_with($norm((string) $l['id']), $norm($p->rt_code.$p->rp_code))) {
                    $rates[(int) $p->id] = (string) $l['id'];
                    break;
                }
            }
        }

        return ['rooms' => $rooms, 'rates' => $rates, 'listings' => $listings];
    }

    private function status(ChannelConnection $connection, string $status, string $action, ?User $by): void
    {
        $before = $connection->status;
        $connection->forceFill(['status' => $status])->save();
        $this->audit->log($action, $connection, ['before' => ['status' => $before], 'after' => ['status' => $status]], $connection->property_id, $by?->id);
    }

    /** Credential values of the provider's fields; an empty secret keeps the stored one. */
    private function credentials(string $provider, array $given, array $current): array
    {
        $out = array_intersect_key($current, ['webhook_secret' => true]);
        foreach ($this->registry->provider($provider)->credentialFields() as $f) {
            $value = trim((string) ($given[$f['key']] ?? ''));
            if ($value === '' && $f['type'] === 'secret' && isset($current[$f['key']])) {
                $value = (string) $current[$f['key']];
            }
            if ($value === '' && ! empty($f['required'])) {
                throw ValidationException::withMessages(['credentials.'.$f['key'] => __('channels.errors.credential_required')]);
            }
            $out[$f['key']] = $value;
        }

        return $out;
    }
}
