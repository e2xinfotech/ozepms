<?php

namespace Tests\Unit\Calendar;

use PHPUnit\Framework\TestCase;

/** Calendar texts: same keys and placeholders in every language, and every key the page uses exists. */
class CalendarTranslationsTest extends TestCase
{
    private const LOCALES = ['fr', 'it', 'de'];

    /** @return array<string, string> */
    private function flat(string $locale): array
    {
        $out = [];
        $walk = function (array $node, string $prefix) use (&$walk, &$out) {
            foreach ($node as $key => $value) {
                is_array($value) ? $walk($value, $prefix.$key.'.') : $out[$prefix.$key] = (string) $value;
            }
        };
        $walk(require dirname(__DIR__, 3)."/lang/{$locale}/calendar.php", '');

        return $out;
    }

    public function test_every_locale_has_the_same_keys_and_placeholders(): void
    {
        $en = $this->flat('en');
        foreach (self::LOCALES as $locale) {
            $other = $this->flat($locale);
            $this->assertSame([], array_values(array_diff(array_keys($en), array_keys($other))), "Missing in {$locale}");
            $this->assertSame([], array_values(array_diff(array_keys($other), array_keys($en))), "Extra in {$locale}");
            foreach ($en as $key => $text) {
                preg_match_all('/:([a-z_]+)/', $text, $a);
                preg_match_all('/:([a-z_]+)/', $other[$key], $b);
                $this->assertEqualsCanonicalizing(array_unique($a[1]), array_unique($b[1]), "Placeholders differ in {$locale}.{$key}");
                if (! in_array($key, ['bar.dates', 'fields.date', 'filters.status', 'bulk.dates', 'edit.no', 'filters.restriction', 'copy.restrictions', 'copy.optional'], true) && strlen($text) > 6) {
                    $this->assertNotSame($text, $other[$key], "Untranslated {$locale}.{$key}");
                }
            }
        }
    }

    public function test_keys_used_by_the_page_exist(): void
    {
        $en = $this->flat('en');
        $dir = dirname(__DIR__, 3).'/resources/js/pages/property/calendar';
        $files = array_merge(glob($dir.'/*.tsx'), glob($dir.'/_components/*.tsx'));
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            preg_match_all("/t\\('calendar\\.([a-z_.]+)'/", file_get_contents($file), $m);
            foreach ($m[1] as $key) {
                $this->assertArrayHasKey($key, $en, basename($file)." uses calendar.{$key}");
            }
        }
    }
}
