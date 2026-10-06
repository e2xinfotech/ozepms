<?php

/*
 * Worker process for InvoiceNumberingConcurrencyTest: boots the application with its own
 * database connection, waits for a shared start time, then issues the tax invoice of one folio
 * through InvoiceService (optionally rolling the transaction back afterwards) and prints JSON.
 *
 * Usage: php invoice-worker.php <json arguments> <start unix time with microseconds>
 */

use App\Domain\Billing\InvoiceService;
use App\Models\Folio;
use App\Models\Property;
use App\Support\PropertyContext;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 4);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$db = json_decode((string) getenv('RACE_DB'), true, flags: JSON_THROW_ON_ERROR);
config(['database.connections.mysql' => array_merge(config('database.connections.mysql'), $db)]);
DB::purge('mysql');

[, $json, $startAt] = $argv;
$args = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

$property = Property::query()->findOrFail($args['property_id']);
app(PropertyContext::class)->set($property, null, true);
$folio = Folio::query()->findOrFail($args['folio_id']);
$service = app(InvoiceService::class);

$wait = (float) $startAt - microtime(true);
if ($wait > 0) {
    usleep((int) ($wait * 1_000_000));
}

try {
    if (! empty($args['abort'])) {
        try {
            DB::transaction(function () use ($service, $folio) {
                $service->issue($folio);
                usleep(20_000);
                throw new RuntimeException('rollback on purpose');
            });
        } catch (RuntimeException) {
        }
        echo json_encode(['ok' => true, 'aborted' => true]);
    } else {
        echo json_encode(['ok' => true, 'number' => $service->issue($folio)->invoice_no]);
    }
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => get_class($e), 'message' => $e->getMessage()]);
}
