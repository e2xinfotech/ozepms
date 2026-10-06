<?php

namespace Tests\Unit\Inventory;

use PHPUnit\Framework\TestCase;

/** The inventory engine texts exist in every language with the same keys and placeholders. */
class InventoryTranslationParityTest extends TestCase
{
    private const GROUPS = ['inventory'];

    private const LOCALES = ['fr', 'it', 'de'];

    /** @return array<string, string> dotted key => text */
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
        foreach (self::GROUPS as $group) {
            $en = $this->flat('en', $group);
            foreach (self::LOCALES as $locale) {
                $other = $this->flat($locale, $group);
                $this->assertSame([], array_values(array_diff(array_keys($en), array_keys($other))), "Missing in {$locale}/{$group}");
                $this->assertSame([], array_values(array_diff(array_keys($other), array_keys($en))), "Extra in {$locale}/{$group}");
                foreach ($en as $key => $text) {
                    preg_match_all('/:([a-z_]+)/', $text, $a);
                    preg_match_all('/:([a-z_]+)/', $other[$key], $b);
                    $this->assertEqualsCanonicalizing(array_unique($a[1]), array_unique($b[1]), "Placeholders differ in {$locale}/{$group}.{$key}");
                }
            }
        }
    }
}
