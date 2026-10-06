<?php

/*
 * Worker process for InventoryRaceTest: boots the application with its own database
 * connection, waits for a shared start time and runs one inventory action, then prints
 * the outcome as JSON. Two of these run at the same moment against committed data.
 *
 * Usage: php race-worker.php <action> <json arguments> <start unix time with microseconds>
 */

use App\Domain\Accommodation\PhysicalUnitService;
use App\Domain\Accommodation\UnitBlockService;
use App\Models\PhysicalUnit;
use App\Models\Property;
use App\Models\RoomType;
use App\Support\PropertyContext;
use Illuminate\Validation\ValidationException;

$root = dirname(__DIR__, 4);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Same database settings as the test process (an empty password in the environment would
// otherwise be replaced by the one in .env).
$db = json_decode((string) getenv('RACE_DB'), true, flags: JSON_THROW_ON_ERROR);
config(['database.connections.mysql' => array_merge(config('database.connections.mysql'), $db)]);
Illuminate\Support\Facades\DB::purge('mysql');

[, $action, $json, $startAt] = $argv;
$args = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

$property = Property::query()->findOrFail($args['property_id']);
app(PropertyContext::class)->set($property, null);
// Open the connection before waiting, so both workers start their work at the same moment.
RoomType::query()->count();

$wait = (float) $startAt - microtime(true);
if ($wait > 0) {
    usleep((int) ($wait * 1_000_000));
}

try {
    match ($action) {
        'add_unit' => app(PhysicalUnitService::class)->addUnits(
            RoomType::query()->findOrFail($args['room_type_id']),
            [['name' => $args['name']]],
        ),
        'block' => app(UnitBlockService::class)->block(
            PhysicalUnit::query()->findOrFail($args['unit_id']),
            $args['type'], $args['from'], $args['to'],
        ),
    };
    echo json_encode(['ok' => true]);
} catch (ValidationException $e) {
    echo json_encode(['ok' => false, 'error' => 'validation', 'message' => collect($e->errors())->flatten()->first()]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => get_class($e), 'message' => $e->getMessage()]);
}
