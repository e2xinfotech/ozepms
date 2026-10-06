<?php

namespace Tests\Unit\Reservations;

use PHPUnit\Framework\TestCase;

/** Reservation and guest texts: same keys and placeholders in every language; every key the pages use exists. */
class ReservationTranslationsTest extends TestCase
{
    private const LOCALES = ['fr', 'it', 'de'];

    /** Same word in some languages (codes, symbols, names). */
    private const SAME = ['ID', 'OTA', 'MICE', 'Direct', 'Transit', 'Documents', 'Actions', 'Source', 'Total', 'Note', 'Notes', 'Contact', 'Type', 'Date', 'Visa', 'Check-in', 'Check-out', 'In-House', 'Walk-in', 'No Show', 'File', 'Reception', 'Status', 'Saldo', 'E-mail', 'Email'];

    /** @return array<string, string> */
    private function flat(string $locale, string $group): array
    {
        $out = [];
        $walk = function (array $node, string $prefix) use (&$walk, &$out) {
            foreach ($node as $key => $value) {
                is_array($value) ? $walk($value, $prefix.$key.'.') : $out[$prefix.$key] = (string) $value;
            }
        };
        $walk(require dirname(__DIR__, 3)."/lang/{$locale}/{$group}.php", '');

        return $out;
    }

    public function test_every_locale_has_the_same_keys_and_placeholders(): void
    {
        foreach (['reservations', 'guests'] as $group) {
            $en = $this->flat('en', $group);
            foreach (self::LOCALES as $locale) {
                $other = $this->flat($locale, $group);
                $this->assertSame([], array_values(array_diff(array_keys($en), array_keys($other))), "Missing in {$locale}/{$group}");
                $this->assertSame([], array_values(array_diff(array_keys($other), array_keys($en))), "Extra in {$locale}/{$group}");
                $same = 0;
                foreach ($en as $key => $text) {
                    preg_match_all('/:([a-z_]+)/', $text, $a);
                    preg_match_all('/:([a-z_]+)/', $other[$key], $b);
                    $this->assertEqualsCanonicalizing(array_unique($a[1]), array_unique($b[1]), "Placeholders differ in {$locale}.{$group}.{$key}");
                    if ($text === $other[$key] && strlen($text) > 8 && ! in_array($text, self::SAME, true) && ! str_starts_with($text, ':')) {
                        $same++;
                    }
                }
                $this->assertLessThan(3, $same, "Too many untranslated texts in {$locale}/{$group}");
            }
        }
    }

    public function test_keys_used_by_the_pages_exist(): void
    {
        $root = dirname(__DIR__, 3).'/resources/js';
        $files = array_merge(
            glob($root.'/pages/property/reservations/*.tsx'), glob($root.'/pages/property/reservations/_components/*.tsx'),
            glob($root.'/pages/property/guests/*.tsx'), glob($root.'/pages/property/guests/_components/*.tsx'),
            glob($root.'/pages/property/front-desk/*.tsx'), [$root.'/components/shell/GlobalSearch.tsx'],
        );
        $this->assertGreaterThan(8, count($files));
        $keys = ['reservations' => $this->flat('en', 'reservations'), 'guests' => $this->flat('en', 'guests')];
        foreach ($files as $file) {
            preg_match_all("/t\\('(reservations|guests)\\.([a-z_.]+)'/", file_get_contents($file), $m, PREG_SET_ORDER);
            foreach ($m as [, $group, $key]) {
                if (str_ends_with($key, '.')) {
                    continue; // dynamic key (template literal)
                }
                $this->assertArrayHasKey($key, $keys[$group], basename($file)." uses {$group}.{$key}");
            }
        }
    }
}
