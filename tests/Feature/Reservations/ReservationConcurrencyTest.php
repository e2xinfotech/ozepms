<?php

namespace Tests\Feature\Reservations;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;

/**
 * Real parallel processes book the last room at the same moment through ReservationService.
 * Exactly one booking may succeed; every other attempt gets a "no longer available" field
 * error (never a deadlock, a server error or a second reservation). A double submit with the
 * same idempotency key creates one booking.
 *
 * Workers must see committed rows, so tests here are not wrapped in a transaction and the
 * database is rebuilt for the next test class.
 */
class ReservationConcurrencyTest extends ReservationTestCase
{
    private const WORKERS = 50;

    public function beginDatabaseTransaction(): void
    {
        // Intentionally empty: workers in other processes must see the committed rows.
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    /** @return list<array<string, mixed>> */
    private function race(array $jobs): array
    {
        $db = config('database.connections.mysql');
        $env = [
            'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => $db['database'],
            'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => (string) $db['password'],
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'LOG_CHANNEL' => 'null',
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'RACE_DB' => json_encode(array_intersect_key($db, array_flip(['host', 'port', 'database', 'username', 'password']))),
        ];
        // Booting 50 workers takes a while on a small machine; all start their booking together.
        $startAt = sprintf('%.6F', microtime(true) + 12.0);
        $worker = __DIR__.'/Support/book-worker.php';

        $procs = [];
        foreach ($jobs as $args) {
            $proc = proc_open([PHP_BINARY, $worker, json_encode($args), $startAt], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
            $this->assertIsResource($proc);
            $procs[] = [$proc, $pipes];
        }
        $results = [];
        foreach ($procs as [$proc, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            proc_close($proc);
            $decoded = json_decode((string) $out, true);
            $this->assertIsArray($decoded, "Worker output: {$out} {$err}");
            $results[] = $decoded;
        }

        return $results;
    }

    private function job(int $n, array $extra = []): array
    {
        return array_merge([
            'n' => $n, 'property_id' => $this->property->id, 'product_id' => $this->steBar->id, 'user_id' => $this->owner->id,
            'from' => $this->day(5)->toDateString(), 'to' => $this->day(8)->toDateString(),
        ], $extra);
    }

    public function test_fifty_parallel_bookings_of_the_last_room_give_exactly_one_reservation(): void
    {
        $results = $this->race(array_map(fn ($n) => $this->job($n), range(1, self::WORKERS)));

        $winners = array_values(array_filter($results, fn ($r) => $r['ok']));
        $this->assertCount(1, $winners, json_encode($results));
        foreach (array_filter($results, fn ($r) => ! $r['ok']) as $loser) {
            $this->assertSame('validation', $loser['error'], json_encode($loser));
            $this->assertSame(['rooms.0.room_type_id'], $loser['fields'], json_encode($loser));
        }
        $this->assertSame(1, DB::table('reservations')->count());
        $this->assertSame(3, DB::table('reservation_room_nights')->where('is_active', 1)->count());
        $this->assertSame([1, 1, 1], $this->sold($this->suite, 5, 8));
        $this->assertInventoryConsistent();
    }

    public function test_parallel_double_submit_with_one_idempotency_key_creates_one_booking(): void
    {
        $results = $this->race(array_map(fn ($n) => $this->job($n, ['product_id' => $this->dlxBar->id, 'key' => 'same-key-1']), range(1, 6)));

        $this->assertCount(6, array_filter($results, fn ($r) => $r['ok']), json_encode($results));
        $this->assertCount(1, array_unique(array_column($results, 'ref')), json_encode($results));
        $this->assertSame(1, DB::table('reservations')->count());
        $this->assertSame([1, 1, 1], $this->sold($this->deluxe, 5, 8));
        $this->assertInventoryConsistent();
    }
}
