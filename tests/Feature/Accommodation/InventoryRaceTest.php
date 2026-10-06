<?php

namespace Tests\Feature\Accommodation;

use App\Models\PhysicalUnit;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\UnitBlock;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

/**
 * Two real processes, each with its own database connection, change the same inventory at
 * the same moment. Exactly one of them may succeed; the other gets a normal validation
 * message (never a server error), and the plan limit is never exceeded.
 *
 * The data must be committed so the worker processes can see it, so this class does not
 * wrap tests in a transaction and asks for a fresh database afterwards.
 */
class InventoryRaceTest extends AccommodationTestCase
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

    /**
     * Starts both workers, lets them begin at the same instant and returns their results.
     *
     * @param  list<array{0: string, 1: array<string, mixed>}>  $jobs
     * @return list<array{ok: bool, error?: string, message?: string}>
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
        $startAt = sprintf('%.6F', microtime(true) + 1.5);
        $worker = __DIR__.'/Support/race-worker.php';

        $procs = [];
        foreach ($jobs as [$action, $args]) {
            $cmd = [PHP_BINARY, $worker, $action, json_encode($args), $startAt];
            $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
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

    /** @param  list<array{ok: bool, error?: string}>  $results */
    private function assertOneWinner(array $results): void
    {
        $this->assertCount(1, array_filter($results, fn ($r) => $r['ok']), json_encode($results));
        $loser = array_values(array_filter($results, fn ($r) => ! $r['ok']))[0];
        $this->assertSame('validation', $loser['error'], json_encode($results));
    }

    public function test_two_requests_cannot_pass_the_room_limit_together(): void
    {
        $roomType = $this->makeRoomType(['code' => 'RACE'], 2);
        $planId = Subscription::query()->where('property_id', $this->property->id)->value('plan_id');
        $active = PhysicalUnit::acrossProperties()->where('property_id', $this->property->id)->where('is_active', true)->count();
        SubscriptionPlan::query()->whereKey($planId)->update(['max_units' => $active + 1]);

        $results = $this->race([
            ['add_unit', ['property_id' => $this->property->id, 'room_type_id' => $roomType->id, 'name' => 'R-A']],
            ['add_unit', ['property_id' => $this->property->id, 'room_type_id' => $roomType->id, 'name' => 'R-B']],
        ]);

        $this->assertOneWinner($results);
        $this->assertSame($active + 1, PhysicalUnit::acrossProperties()->where('property_id', $this->property->id)->where('is_active', true)->count());
    }

    public function test_two_requests_creating_the_same_room_name_get_one_room(): void
    {
        $roomType = $this->makeRoomType(['code' => 'SAME'], 1);

        $results = $this->race([
            ['add_unit', ['property_id' => $this->property->id, 'room_type_id' => $roomType->id, 'name' => 'R-900']],
            ['add_unit', ['property_id' => $this->property->id, 'room_type_id' => $roomType->id, 'name' => 'R-900']],
        ]);

        $this->assertOneWinner($results);
        $this->assertSame(1, PhysicalUnit::acrossProperties()->where('property_id', $this->property->id)->where('name', 'R-900')->count());
    }

    public function test_two_blocks_of_the_same_room_and_dates_create_one_block(): void
    {
        $unit = $this->firstUnit($this->makeRoomType(['code' => 'BLK'], 1));
        $from = date('Y-m-d', strtotime($this->today().' +3 days'));
        $to = date('Y-m-d', strtotime($from.' +2 days'));
        $args = ['property_id' => $this->property->id, 'unit_id' => $unit->id, 'type' => 'out_of_order', 'from' => $from, 'to' => $to];

        $results = $this->race([['block', $args], ['block', $args]]);

        $this->assertOneWinner($results);
        $this->assertSame(1, UnitBlock::acrossProperties()->where('unit_id', $unit->id)->count());
    }
}
