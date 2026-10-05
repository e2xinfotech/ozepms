<?php

namespace App\Infrastructure\Database;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The only way application code opens a database transaction.
 * Deadlocks and lock wait timeouts are retried automatically.
 */
final class Tx
{
    public static function run(Closure $callback, int $attempts = 3): mixed
    {
        try {
            return DB::transaction($callback, $attempts);
        } catch (Throwable $e) {
            if (self::isConcurrencyError($e)) {
                Log::warning('Transaction failed after retries', ['exception' => $e::class, 'attempts' => $attempts]);
            }
            throw $e;
        }
    }

    private static function isConcurrencyError(Throwable $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'Deadlock') || str_contains($message, 'Lock wait timeout');
    }
}
