<?php

namespace App\Infrastructure\Logging;

use App\Support\PropertyContext;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Groups errors by fingerprint into system_error_events for the Super Admin
 * "System health" screen. Full details always remain in the log files.
 */
final class ErrorRecorder
{
    /** Where code is running now: server (web request), queue (job) or scheduler (scheduled task). */
    private static string $source = 'server';

    public static function runningIn(string $source): void
    {
        self::$source = $source;
    }

    public static function currentSource(): string
    {
        return self::$source;
    }

    public static function exception(Throwable $e, ?string $source = null, string $level = 'error'): void
    {
        $source ??= self::$source;
        $location = basename($e->getFile()).':'.$e->getLine();
        self::record(
            fingerprint: sha1($e::class.'|'.$e->getFile().'|'.$e->getLine()),
            level: $level,
            source: $source,
            class: $e::class,
            message: $e->getMessage() ?: $e::class,
            location: $location,
        );
    }

    public static function record(string $fingerprint, string $level, string $source, ?string $class, string $message, ?string $location): void
    {
        try {
            $now = now();
            $request = app()->bound('request') ? request() : null;

            DB::table('system_error_events')->upsert([[
                'fingerprint' => $fingerprint,
                'level' => $level,
                'source' => $source,
                'exception_class' => $class ? mb_substr($class, 0, 190) : null,
                'message' => mb_substr($message, 0, 1000),
                'location' => $location ? mb_substr($location, 0, 255) : null,
                'last_request_id' => $request?->attributes->get('request_id'),
                'last_property_id' => app(PropertyContext::class)->idOrNull(),
                'last_user_id' => auth()->id(),
                'occurrences' => 1,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'resolved_at' => null,
            ]], ['fingerprint'], [
                'occurrences' => DB::raw('occurrences + 1'),
                'last_seen_at' => $now,
                'last_request_id' => $request?->attributes->get('request_id'),
                'last_property_id' => app(PropertyContext::class)->idOrNull(),
                'last_user_id' => auth()->id(),
                'message' => mb_substr($message, 0, 1000),
                'resolved_at' => null,
            ]);
        } catch (Throwable) {
            // Never let error bookkeeping cause another error (e.g. database down).
        }
    }
}
