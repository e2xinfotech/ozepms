<?php

namespace App\Domain\Channels;

use App\Domain\Channels\Data\AriUpdate;
use App\Domain\Channels\Data\ProviderResult;
use App\Domain\Inventory\InventoryService;
use App\Models\ChannelConnection;
use App\Models\ChannelSyncLog;
use App\Models\Product;
use App\Models\Property;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Outbound sync: availability, prices and restrictions from the PMS to a channel.
 *
 * Delta: ari_change_log rows after the connection's high-water mark (last_ari_log_id) tell which
 * room types / products and dates changed; their CURRENT values are computed (AriSnapshot) and
 * sent, merged into runs of equal values. Only mapped rooms and rates, only the next
 * config('channels.sync_days') days. The mark moves only when every message was accepted, so a
 * failed run is simply repeated (values are states, not increments).
 * Full sync: every mapped room and rate for the whole window.
 * Failures: retried at most config('channels.max_retries') times, each after config('channels.retry_after_minutes');
 * then the connection shows "error" and stays quiet until someone presses "Try again". A refusal that cannot
 * succeed by repeating (wrong credentials) stops at once.
 * Freshness: every attempt computes the values from the calendar at that moment. If the calendar changes
 * for the same rooms / rates while a run is working, the run stops before sending older values and the
 * next run starts again from the newest data.
 */
class ChannelSyncService
{
    public function __construct(
        private readonly ChannelRegistry $registry,
        private readonly AriSnapshot $snapshot,
        private readonly InventoryService $inventory,
    ) {}

    /** Connections with pending changes whose next attempt is due (channels:sync, every minute). */
    public function syncDue(): int
    {
        $count = 0;
        // Connections in "error" are not retried by the scheduler (the retries are used up).
        ChannelConnection::acrossProperties()->where('status', 'active')->where('approval_status', 'approved')
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')->get()
            ->each(function (ChannelConnection $c) use (&$count) {
                if (! $this->registry->available($c->provider)) {
                    return;
                }
                $pending = DB::table('ari_change_log')->where('property_id', $c->property_id)->where('id', '>', $c->last_ari_log_id)->exists();
                if ($pending || $c->failures > 0) {
                    $this->sync($c);
                    $count++;
                }
            });

        return $count;
    }

    /** @return array{status: string, updates: int, message: ?string} */
    public function sync(ChannelConnection $connection, bool $full = false): array
    {
        // Nothing goes to a channel before E2X has approved the connection (and never while it is suspended).
        if (! $connection->isApproved()) {
            return ['status' => 'unapproved', 'updates' => 0, 'message' => null];
        }
        $lock = Cache::lock('channel-sync:'.$connection->id, 300);
        if (! $lock->get()) {
            return ['status' => 'busy', 'updates' => 0, 'message' => null];
        }
        try {
            return $this->run($connection->fresh(), $full);
        } finally {
            $lock->release();
        }
    }

    private function run(ChannelConnection $c, bool $full): array
    {
        $property = Property::query()->withoutGlobalScopes()->findOrFail($c->property_id);
        $from = $this->inventory->today($property->id);
        $to = $from->addDays(max(1, (int) config('channels.sync_days', 365)) - 1);

        $rooms = $c->roomMappings()->get()->keyBy('room_type_id');
        $rates = $c->rateMappings()->with('product')->get()->filter(fn ($m) => $m->product !== null && $rooms->has($m->product->room_type_id))->keyBy('product_id');

        $maxId = (int) DB::table('ari_change_log')->where('property_id', $property->id)->max('id');
        if ($full) {
            $roomRanges = $rooms->keys()->mapWithKeys(fn ($id) => [(int) $id => [$from, $to]])->all();
            $rateRanges = $rates->keys()->mapWithKeys(fn ($id) => [(int) $id => [$from, $to]])->all();
        } else {
            $logs = DB::table('ari_change_log')->where('property_id', $property->id)->where('id', '>', $c->last_ari_log_id)
                ->orderBy('id')->limit((int) config('channels.log_batch', 5000))->get(['id', 'scope', 'room_type_id', 'product_id', 'date_from', 'date_to']);
            if ($logs->isNotEmpty()) {
                $maxId = (int) $logs->last()->id;
            }
            [$roomRanges, $rateRanges] = $this->affected($logs, $property->id, $from, $to);
            $roomRanges = array_intersect_key($roomRanges, $rooms->all());
            $rateRanges = array_intersect_key($rateRanges, $rates->all());
        }

        $updates = $this->updates($property, $rooms, $rates, $roomRanges, $rateRanges, $from, $to);
        if ($updates === []) {
            $c->forceFill(['last_ari_log_id' => max($c->last_ari_log_id, $maxId), 'failures' => 0, 'next_attempt_at' => null, 'last_error' => null]
                + ($full ? ['last_full_sync_at' => now(), 'last_success_at' => now()] : []) + ($c->status === 'error' ? ['status' => 'active'] : []))->save();

            return ['status' => 'nothing', 'updates' => 0, 'message' => null];
        }

        $provider = $this->registry->provider($c);
        $type = $full ? 'full_sync' : 'ari_update';
        foreach (array_chunk($updates, max(1, (int) config('channels.batch_size', 500))) as $chunk) {
            // The calendar changed for these rooms / rates after the values were read: do not send old data.
            if ($this->changedSince($property->id, $maxId, $roomRanges, $rateRanges)) {
                return ['status' => 'superseded', 'updates' => 0, 'message' => null];
            }
            try {
                $result = $provider->pushAri($c, $chunk);
            } catch (Throwable $e) {
                report($e);
                $result = ProviderResult::fail($e->getMessage());
            }
            $this->log($c, 'outbound', $type, $result, count($chunk), $this->summary($chunk));
            if (! $result->ok) {
                $this->failed($c, (string) $result->message, $result->retryable);

                return ['status' => 'failed', 'updates' => count($updates), 'message' => $result->message];
            }
        }

        $c->forceFill(['last_ari_log_id' => max($c->last_ari_log_id, $maxId), 'failures' => 0, 'next_attempt_at' => null, 'last_error' => null,
            'last_success_at' => now(), 'status' => $c->status === 'error' ? 'active' : $c->status] + ($full ? ['last_full_sync_at' => now()] : []))->save();

        return ['status' => 'sent', 'updates' => count($updates), 'message' => null];
    }

    /**
     * Room types and products touched by the change-log rows, with the date range per id.
     * A rate / restriction change of a product also touches the products derived from it.
     *
     * @return array{0: array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>, 1: array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>}
     */
    private function affected($logs, int $propertyId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rooms = [];
        $products = [];
        $add = function (array &$set, int $id, string $a, string $b) use ($from, $to) {
            $s = CarbonImmutable::parse($a)->max($from);
            $e = CarbonImmutable::parse($b)->min($to);
            if ($s->greaterThan($e)) {
                return;
            }
            $set[$id] = isset($set[$id]) ? [$set[$id][0]->min($s), $set[$id][1]->max($e)] : [$s, $e];
        };
        $children = null;
        foreach ($logs as $l) {
            if ($l->scope === 'inventory' || ($l->product_id === null && $l->room_type_id !== null)) {
                $add($rooms, (int) $l->room_type_id, $l->date_from, $l->date_to);

                continue;
            }
            if ($l->product_id === null) {
                continue;
            }
            $children ??= DB::table('room_type_rate_plans')->where('property_id', $propertyId)->whereNotNull('parent_product_id')
                ->get(['id', 'parent_product_id'])->groupBy('parent_product_id')->map(fn ($g) => $g->pluck('id')->map(fn ($v) => (int) $v)->all())->all();
            $queue = [(int) $l->product_id];
            $seen = [];
            while ($queue !== []) {
                $pid = array_shift($queue);
                if (isset($seen[$pid])) {
                    continue;
                }
                $seen[$pid] = true;
                $add($products, $pid, $l->date_from, $l->date_to);
                foreach ($children[$pid] ?? [] as $child) {
                    $queue[] = $child;
                }
            }
        }

        return [$rooms, $products];
    }

    /** @return list<AriUpdate> */
    private function updates(Property $property, $rooms, $rates, array $roomRanges, array $rateRanges, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = [];
        if ($roomRanges !== []) {
            $min = collect($roomRanges)->min(fn ($r) => $r[0]->toDateString());
            $max = collect($roomRanges)->max(fn ($r) => $r[1]->toDateString());
            $values = $this->snapshot->rooms((int) $property->id, array_keys($roomRanges), CarbonImmutable::parse($min), CarbonImmutable::parse($max));
            foreach ($roomRanges as $rtId => [$a, $b]) {
                $ext = (string) $rooms[$rtId]->external_room_id;
                $days = array_filter($values[$rtId] ?? [], fn ($d) => $d >= $a->toDateString() && $d <= $b->toDateString(), ARRAY_FILTER_USE_KEY);
                $out = [...$out, ...$this->runs($days, fn ($v) => ['availability' => $v['availability'], 'stop_sell' => $v['stop_sell']], $ext, null)];
            }
        }
        if ($rateRanges !== []) {
            $min = collect($rateRanges)->min(fn ($r) => $r[0]->toDateString());
            $max = collect($rateRanges)->max(fn ($r) => $r[1]->toDateString());
            $values = $this->snapshot->rates($property, array_keys($rateRanges), CarbonImmutable::parse($min), CarbonImmutable::parse($max));
            $places = Money::minorUnits((string) $property->currency_code);
            foreach ($rateRanges as $pid => [$a, $b]) {
                $m = $rates[$pid];
                /** @var Product $product */
                $product = $m->product;
                $roomExt = (string) $rooms[$product->room_type_id]->external_room_id;
                $days = array_filter($values[$pid] ?? [], fn ($d) => $d >= $a->toDateString() && $d <= $b->toDateString(), ARRAY_FILTER_USE_KEY);
                $out = [...$out, ...$this->runs($days, fn ($v) => [
                    'price' => AriSnapshot::withMarkup($v['price'], (string) $m->markup_type, $m->markup_value === null ? null : (string) $m->markup_value, $places),
                    'min_los' => $v['min_los'], 'max_los' => $v['max_los'], 'cta' => $v['cta'], 'ctd' => $v['ctd'], 'stop_sell' => $v['stop_sell'],
                ], $roomExt, (string) $m->external_rate_id)];
            }
        }

        return $out;
    }

    /**
     * Merges consecutive days with the same values into one update.
     *
     * @param  array<string, array<string, mixed>>  $days  date => snapshot values
     * @return list<AriUpdate>
     */
    private function runs(array $days, callable $map, string $roomId, ?string $rateId): array
    {
        ksort($days);
        $out = [];
        $start = $prev = null;
        $current = null;
        $flush = function () use (&$out, &$start, &$prev, &$current, $roomId, $rateId) {
            if ($start === null) {
                return;
            }
            $out[] = new AriUpdate($roomId, $rateId, $start, $prev,
                availability: $current['availability'] ?? null, price: $current['price'] ?? null,
                minLos: $current['min_los'] ?? null, maxLos: $current['max_los'] ?? null,
                cta: $current['cta'] ?? null, ctd: $current['ctd'] ?? null, stopSell: $current['stop_sell'] ?? null);
        };
        foreach ($days as $date => $v) {
            $values = $map($v);
            $contiguous = $prev !== null && date('Y-m-d', strtotime($prev.' +1 day')) === $date;
            if ($start === null || ! $contiguous || $values !== $current) {
                $flush();
                $start = $date;
                $current = $values;
            }
            $prev = $date;
        }
        $flush();

        return $out;
    }

    private function failed(ChannelConnection $c, string $message, bool $retryable = true): void
    {
        $failures = $c->failures + 1;
        // First failure + max_retries retries; a refusal that repeating cannot fix stops at once.
        $giveUp = ! $retryable || $failures > (int) config('channels.max_retries', 2);
        $c->forceFill([
            'failures' => $failures, 'last_error' => mb_substr($message, 0, 1000), 'last_error_at' => now(),
            'next_attempt_at' => $giveUp ? null : now()->addMinutes(max(1, (int) config('channels.retry_after_minutes', 5))),
            'status' => $giveUp && $c->status === 'active' ? 'error' : $c->status,
        ])->save();
        if ($giveUp) {
            // The last attempt is final: the log shows "Failed", not "Will retry".
            ChannelSyncLog::query()->where('connection_id', $c->id)->where('status', 'retrying')->orderByDesc('id')->limit(1)->update(['status' => 'failed']);
        }
    }

    /**
     * True when the calendar changed (change-log rows after $maxId) for any room type / rate and dates
     * that this run is about to send. Such a run is dropped; the next one reads the newest values.
     *
     * @param  array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>  $roomRanges
     * @param  array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>  $rateRanges
     */
    private function changedSince(int $propertyId, int $maxId, array $roomRanges, array $rateRanges): bool
    {
        $newer = DB::table('ari_change_log')->where('property_id', $propertyId)->where('id', '>', $maxId)->limit(2000)->get(['id', 'scope', 'room_type_id', 'product_id', 'date_from', 'date_to']);
        if ($newer->isEmpty()) {
            return false;
        }
        $far = CarbonImmutable::parse('2000-01-01');
        [$rooms, $products] = $this->affected($newer, $propertyId, $far, CarbonImmutable::parse('2100-01-01'));
        foreach ([[$rooms, $roomRanges], [$products, $rateRanges]] as [$changed, $sending]) {
            foreach ($changed as $id => [$a, $b]) {
                if (isset($sending[$id]) && $a->lessThanOrEqualTo($sending[$id][1]) && $b->greaterThanOrEqualTo($sending[$id][0])) {
                    return true;
                }
            }
        }

        return false;
    }

    /** One sync log row (bodies cut to 200 KB). */
    public function log(ChannelConnection $c, string $direction, string $type, ProviderResult $result, int $items, ?string $summary): ChannelSyncLog
    {
        $cut = fn (?string $s) => $s === null ? null : mb_substr($s, 0, 200000);

        return ChannelSyncLog::query()->create([
            'connection_id' => $c->id, 'direction' => $direction, 'message_type' => $type, 'summary' => $summary ? mb_substr($summary, 0, 255) : null,
            'items' => $items, 'status' => $result->ok ? 'success' : ($result->retryable ? 'retrying' : 'failed'), 'attempts' => $c->failures + 1,
            'request_body' => $cut($result->request), 'response_body' => $cut($result->response), 'error' => $result->ok ? null : mb_substr((string) $result->message, 0, 1000),
        ]);
    }

    /** "12 updates · 3 rooms · 5 rates · 07 Oct – 31 Dec" (stored; the log viewer shows it). */
    private function summary(array $chunk): string
    {
        $rooms = count(array_unique(array_map(fn (AriUpdate $u) => $u->roomId, $chunk)));
        $rates = count(array_unique(array_filter(array_map(fn (AriUpdate $u) => $u->rateId, $chunk))));
        $from = min(array_map(fn (AriUpdate $u) => $u->from, $chunk));
        $to = max(array_map(fn (AriUpdate $u) => $u->to, $chunk));

        return json_encode(['updates' => count($chunk), 'rooms' => $rooms, 'rates' => $rates, 'from' => $from, 'to' => $to]);
    }
}
