<?php

namespace Tests\Unit\Billing;

use Illuminate\Support\Arr;
use PHPUnit\Framework\TestCase;

/** lang/{en,fr,it,de}/billing.php: same keys and placeholders in every language, and real translations. */
class BillingTranslationsTest extends TestCase
{
    private function load(string $locale): array
    {
        return Arr::dot(require dirname(__DIR__, 3)."/lang/{$locale}/billing.php");
    }

    public function test_same_keys_and_placeholders(): void
    {
        $en = $this->load('en');
        foreach (['fr', 'it', 'de'] as $locale) {
            $other = $this->load($locale);
            $this->assertSame([], array_values(array_diff(array_keys($en), array_keys($other))), "$locale is missing keys");
            $this->assertSame([], array_values(array_diff(array_keys($other), array_keys($en))), "$locale has extra keys");
            $same = 0;
            foreach ($en as $key => $text) {
                preg_match_all('/:[a-z_]+/', $text, $a);
                preg_match_all('/:[a-z_]+/', $other[$key], $b);
                sort($a[0]);
                sort($b[0]);
                $this->assertSame($a[0], $b[0], "$locale.$key placeholders");
                $same += $text === $other[$key] ? 1 : 0;
            }
            $this->assertLessThan(count($en) * 0.1, $same, "$locale looks untranslated");
        }
    }

    public function test_keys_used_by_the_pages_exist(): void
    {
        $en = $this->load('en');
        $root = dirname(__DIR__, 3).'/resources/js/pages/property';
        $files = array_merge(glob($root.'/reservations/_components/billing/*.tsx'), glob($root.'/billing/*.tsx'), glob($root.'/services/*.tsx'));
        $missing = [];
        foreach ($files as $file) {
            preg_match_all("/t\\('billing\\.([a-z_.]+)'/", (string) file_get_contents($file), $m);
            foreach ($m[1] as $key) {
                if (! array_key_exists($key, $en)) {
                    $missing[] = basename($file).': '.$key;
                }
            }
        }
        $this->assertSame([], $missing);
    }
}
