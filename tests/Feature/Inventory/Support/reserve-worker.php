<?php

/*
 * Worker process for InventoryConcurrencyTest: boots the application with its own database
 * connection, waits for a shared start time, then reserves rooms in its own transaction and
 * prints the outcome as JSON.
 *
 * Usage: php reserve-worker.php <json arguments> <start unix time with microseconds>
 */

use App\Domain\Inventory\Exceptions\NotAvailableException;
use App\Domain\Inventory\InventoryService;
use App\Infrastructure\Database\Tx;
use Carbon\CarbonImmutable;

$root = dirname(__DIR__, 4);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$db = json_decode((string) getenv('RACE_DB'), true, flags: JSON_THROW_ON_ERROR);
config(['database.connections.mysql' => array_merge(config('database.connections.mysql'), $db)]);
Illuminate\Support\Facades\DB::purge('mysql');

[, $json, $startAt] = $argv;
$args = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

// Connect before waiting, so every worker starts its transaction at the same moment.
Illuminate\Support\Facades\DB::select('select 1');
$inventory = app(InventoryService::class);

$wait = (float) $startAt - microtime(true);
if ($wait > 0) {
    usleep((int) ($wait * 1_000_000));
}

try {
    Tx::run(function () use ($inventory, $args) {
        $inventory->reserve(
            (int) $args['room_type_id'],
            CarbonImmutable::parse($args['from']),
            CarbonImmutable::parse($args['to']),
            (int) ($args['rooms'] ?? 1),
            (bool) ($args['hold'] ?? false),
        );
        // Keep the transaction open a moment, as a real booking does (reservation rows, folio).
        usleep(50_000);
    });
    echo json_encode(['ok' => true]);
} catch (NotAvailableException $e) {
    echo json_encode(['ok' => false, 'error' => 'not_available', 'dates' => $e->dates]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => get_class($e), 'message' => $e->getMessage()]);
}
