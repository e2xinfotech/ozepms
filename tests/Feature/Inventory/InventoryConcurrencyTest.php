<?php

namespace Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;

/**
 * Real parallel processes, each with its own database connection, reserve the same rooms at
 * the same moment. The guarded UPDATE must let exactly as many succeed as there are rooms;
 * every other process gets NotAvailableException (never a deadlock or server error).
 *
 * Workers must see committed rows, so tests here are not wrapped in a transaction and the
 * database is rebuilt for the next test class.
 */
class InventoryConcurrencyTest extends InventoryTestCase
{
    private const WORKERS = 8;

    public function beginDatabaseTransaction(): void
    {
        // Intentionally empty: workers in other processes must see the committed rows.
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    /**
     * @param  list<array<string, mixed>>  $jobs
     * @return list<array{ok: bool, error?: string}>
     */
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
        $startAt = sprintf('%.6F', microtime(true) + 2.0);
        $worker = __DIR__.'/Support/reserve-worker.php';

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

    public function test_only_one_of_many_parallel_bookings_gets_the_last_room(): void
    {
        $roomType = $this->makeRoomType([], 1);
        $job = ['room_type_id' => $roomType->id, 'from' => $this->day(5)->toDateString(), 'to' => $this->day(8)->toDateString()];

        $results = $this->race(array_fill(0, self::WORKERS, $job));

        $this->assertCount(1, array_filter($results, fn ($r) => $r['ok']), json_encode($results));
        foreach (array_filter($results, fn ($r) => ! $r['ok']) as $loser) {
            $this->assertSame('not_available', $loser['error'], json_encode($results));
        }
        $this->assertSame([1, 1, 1], DB::table('inventory_daily')->where('room_type_id', $roomType->id)
            ->whereBetween('stay_date', [$this->day(5)->toDateString(), $this->day(7)->toDateString()])
            ->orderBy('stay_date')->pluck('sold')->map(fn ($v) => (int) $v)->all());
    }

    public function test_overlapping_stays_and_holds_never_exceed_the_rooms(): void
    {
        $roomType = $this->makeRoomType([], 3);
        $jobs = [];
        for ($i = 0; $i < self::WORKERS; $i++) {
            // Different but overlapping stays; every one of them covers day 6.
            $jobs[] = ['room_type_id' => $roomType->id, 'from' => $this->day(3 + $i % 4)->toDateString(), 'to' => $this->day(7 + $i % 3)->toDateString(), 'hold' => $i % 2 === 1];
        }

        $results = $this->race($jobs);

        $this->assertCount(3, array_filter($results, fn ($r) => $r['ok']), json_encode($results));
        $this->assertCount(0, array_filter($results, fn ($r) => ! $r['ok'] && $r['error'] !== 'not_available'), json_encode($results));
        $max = DB::table('inventory_daily')->where('room_type_id', $roomType->id)->max(DB::raw('sold + held + ooo_units'));
        $this->assertSame(3, (int) $max);
    }
}
