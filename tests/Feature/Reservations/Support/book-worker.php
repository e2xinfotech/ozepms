<?php

/*
 * Worker process for ReservationConcurrencyTest: boots the application with its own database
 * connection, waits for a shared start time, then books through ReservationService::create
 * (the same path as the front desk) and prints the outcome as JSON.
 *
 * Usage: php book-worker.php <json arguments> <start unix time with microseconds>
 */

use App\Domain\Reservations\ReservationService;
use App\Models\Product;
use App\Models\Property;
use App\Models\User;
use App\Support\PropertyContext;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

$root = dirname(__DIR__, 4);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$db = json_decode((string) getenv('RACE_DB'), true, flags: JSON_THROW_ON_ERROR);
config(['database.connections.mysql' => array_merge(config('database.connections.mysql'), $db)]);
Illuminate\Support\Facades\DB::purge('mysql');

[, $json, $startAt] = $argv;
$args = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

$property = Property::query()->findOrFail($args['property_id']);
app(PropertyContext::class)->set($property, null, true);
$product = Product::query()->findOrFail($args['product_id']);
$user = User::query()->find($args['user_id']);
$service = app(ReservationService::class);

$wait = (float) $startAt - microtime(true);
if ($wait > 0) {
    usleep((int) ($wait * 1_000_000));
}

try {
    $reservation = $service->create($property, [
        'status' => 'confirmed',
        'idempotency_key' => $args['key'] ?? null,
        'guest' => ['first_name' => 'Guest', 'last_name' => (string) $args['n'], 'email' => 'guest'.$args['n'].'@example.test'],
        'rooms' => [['product' => $product, 'check_in' => CarbonImmutable::parse($args['from']), 'check_out' => CarbonImmutable::parse($args['to']), 'adults' => 2]],
    ], $user);
    echo json_encode(['ok' => true, 'ref' => $reservation->booking_ref, 'replayed' => $service->replayed]);
} catch (ValidationException $e) {
    echo json_encode(['ok' => false, 'error' => 'validation', 'fields' => array_keys($e->errors())]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => get_class($e), 'message' => $e->getMessage()]);
}
