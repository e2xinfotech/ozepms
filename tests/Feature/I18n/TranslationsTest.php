<?php

namespace Tests\Feature\I18n;

use App\Domain\Audit\Queries\AuditQuery;
use Tests\TestCase;

/** Phase 1 texts exist in English, French, Italian and German, including the built-in validation messages. */
class TranslationsTest extends TestCase
{
    private const GROUPS = ['ui', 'nav', 'errors', 'auth', 'mail', 'users', 'roles', 'property', 'subscription', 'admin',
        'permissions', 'property_type', 'validation', 'passwords', 'pagination'];

    /** @return array<string, string> */
    private function flat(string $locale, string $group): array
    {
        $out = [];
        $walk = function (array $node, string $prefix) use (&$walk, &$out) {
            foreach ($node as $key => $value) {
                is_array($value) ? $walk($value, $prefix.$key.'.') : $out[$prefix.$key] = (string) $value;
            }
        };
        $walk(require lang_path("{$locale}/{$group}.php"), '');

        return $out;
    }

    public function test_every_locale_has_the_same_keys_and_placeholders(): void
    {
        foreach (self::GROUPS as $group) {
            $en = $this->flat('en', $group);
            foreach (['fr', 'it', 'de'] as $locale) {
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

    public function test_validation_messages_follow_the_language(): void
    {
        $expected = ['fr' => 'Le champ adresse e-mail est obligatoire.', 'it' => 'Il campo indirizzo e-mail è obbligatorio.', 'de' => 'Das Feld E-Mail-Adresse ist erforderlich.', 'en' => 'The email address field is required.'];

        foreach ($expected as $locale => $message) {
            $this->withSession(['locale' => $locale])->postJson('/web-api/auth/forgot-password', ['email' => ''])
                ->assertStatus(422)->assertJsonPath('error.fields.email.0', $message);
        }
    }

    public function test_signed_in_users_get_messages_in_their_language(): void
    {
        [$property, $owner] = $this->createPropertyWithOwner();
        $owner->forceFill(['locale' => 'de'])->save();

        $this->actingAs($owner)->putJson("/web-api/p/{$property->code}/settings", ['name' => ''])
            ->assertStatus(422)->assertJsonPath('error.fields.name.0', 'Das Feld Name ist erforderlich.');
    }

    public function test_audit_actions_have_readable_labels(): void
    {
        app()->setLocale('fr');
        $this->assertSame('Type de chambre créé', AuditQuery::actionLabel('room_type.created'));
        app()->setLocale('en');
        $this->assertSame('Property copied', AuditQuery::actionLabel('property.copied'));
        $this->assertSame('Something new happened', AuditQuery::actionLabel('something_new.happened'));
    }

    public function test_system_role_names_are_translated_but_custom_names_are_kept(): void
    {
        app()->setLocale('it');
        $this->assertSame('Proprietario', \App\Support\RoleLabel::name('owner', 'Owner'));
        $this->assertSame('Night Owl', \App\Support\RoleLabel::name('owner', 'Night Owl'));
        $this->assertSame('Night Audit', \App\Support\RoleLabel::name('night_audit', 'Night Audit'));
    }
}
