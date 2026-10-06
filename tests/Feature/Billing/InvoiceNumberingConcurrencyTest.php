<?php

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;

/**
 * Real parallel processes issue invoices of the same property at the same moment; some of them
 * roll their transaction back. The series must have no duplicates and no gaps.
 * Workers must see committed rows, so this test is not wrapped in a transaction.
 */
class InvoiceNumberingConcurrencyTest extends BillingTestCase
{
    public function beginDatabaseTransaction(): void
    {
        // Intentionally empty: workers in other processes must see the committed rows.
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_parallel_invoices_get_unique_gap_free_numbers(): void
    {
        $jobs = [];
        for ($i = 0; $i < 16; $i++) {
            $r = $this->book([$this->room($this->dlxBar, 1 + intdiv($i, 3), 2 + intdiv($i, 3))]);
            $this->within(fn () => $this->folios()->postRoomNights($r, null, $this->owner));
            $jobs[] = ['property_id' => $this->property->id, 'folio_id' => $this->folioOf($r)->id, 'abort' => $i % 4 === 3];
        }

        $db = config('database.connections.mysql');
        $env = [
            'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'LOG_CHANNEL' => 'null',
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'RACE_DB' => json_encode(array_intersect_key($db, array_flip(['host', 'port', 'database', 'username', 'password']))),
        ];
        $startAt = sprintf('%.6F', microtime(true) + 6.0);
        $procs = [];
        foreach ($jobs as $job) {
            $proc = proc_open([PHP_BINARY, __DIR__.'/Support/invoice-worker.php', json_encode($job), $startAt], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
            $procs[] = [$proc, $pipes];
        }
        $results = [];
        foreach ($procs as [$proc, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            proc_close($proc);
            $decoded = json_decode((string) $out, true);
            $this->assertIsArray($decoded, "Worker output: {$out} {$err}");
            $this->assertTrue($decoded['ok'], json_encode($decoded));
            $results[] = $decoded;
        }

        $numbers = DB::table('invoices')->where('property_id', $this->property->id)->orderBy('invoice_no')->pluck('invoice_no')->all();
        $this->assertCount(12, $numbers);
        $this->assertSame(array_unique($numbers), $numbers);
        $seq = array_map(fn ($n) => (int) substr($n, strrpos($n, '/') + 1), $numbers);
        $this->assertSame(range(1, 12), $seq, 'gap-free 1..12 although 4 workers rolled back');
    }
}
