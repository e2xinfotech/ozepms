<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Translations shipped to the browser. All UI text lives in lang/{locale}/*.php.
 * Every group in lang/en is sent to React pages except SERVER_ONLY (cached per locale).
 */
final class Translations
{
    /** Server-only groups that the browser never needs. */
    private const SERVER_ONLY = ['validation', 'mail', 'passwords', 'pagination'];

    public static function forClient(): array
    {
        $locale = app()->getLocale();
        $key = 'i18n:'.$locale.':'.self::version();

        return Cache::rememberForever($key, function () {
            $out = [];
            foreach (glob(lang_path('en/*.php')) ?: [] as $file) {
                $group = basename($file, '.php');
                if (! in_array($group, self::SERVER_ONLY, true)) {
                    $out[$group] = trans($group);
                }
            }

            return $out;
        });
    }

    /** Changes whenever a language file changes, so edits show up without clearing caches. */
    private static function version(): string
    {
        $files = glob(lang_path('*/*.php')) ?: [];

        return (string) array_reduce($files, fn ($carry, $f) => max($carry, filemtime($f)), 0);
    }
}
