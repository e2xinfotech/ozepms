<?php

namespace App\Infrastructure\Logging;

/**
 * Removes secrets from arrays before they are written to logs or audit trails.
 */
final class Redactor
{
    private const MASK = '[redacted]';

    public static function clean(mixed $data, int $depth = 0): mixed
    {
        if (! is_array($data) || $depth > 6) {
            return $data;
        }

        $keys = array_map('strtolower', config('ozepms.logging.redact_keys', []));

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $keys, true)) {
                $data[$key] = self::MASK;
            } elseif (is_array($value)) {
                $data[$key] = self::clean($value, $depth + 1);
            }
        }

        return $data;
    }
}
