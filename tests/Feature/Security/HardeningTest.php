<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Hash;
use Tests\Feature\Accommodation\AccommodationTestCase;

/** Responses must not reveal the server framework or internal class names. */
class HardeningTest extends AccommodationTestCase
{
    public function test_passwords_are_hashed_with_argon2id(): void
    {
        // Tests run with bcrypt for speed (phpunit.xml); every other environment defaults to Argon2id.
        $this->assertStringContainsString("env('HASH_DRIVER', 'argon2id')", (string) file_get_contents(config_path('hashing.php')));
        $this->assertStringContainsString('HASH_DRIVER=argon2id', (string) file_get_contents(base_path('.env.example')));
        $this->assertStringStartsWith('$argon2id$', Hash::driver('argon2id')->make('Secret#12345'));
    }

    public function test_cookie_names_are_neutral(): void
    {
        $this->assertSame('oz_rm', auth()->guard('web')->getRecallerName());
        $this->assertStringNotContainsString('laravel', strtolower((string) config('session.cookie')));

        $response = $this->get('/login');
        foreach ($response->headers->getCookies() as $cookie) {
            $this->assertNotContains($cookie->getName(), ['XSRF-TOKEN', 'laravel_session']);
        }
    }

    public function test_json_errors_do_not_leak_framework_messages(): void
    {
        $this->actingAs($this->owner)
            ->getJson($this->api('/room-types/01ABCDEFGHJKMNPQRSTVWXYZ00'))
            ->assertNotFound()
            ->assertJsonPath('error.message', __('errors.404'))
            ->assertDontSee('App\\\\Models', false);

        $this->actingAs($this->owner)->deleteJson('/web-api/locale')
            ->assertStatus(405)
            ->assertDontSee('Supported methods');
    }

    public function test_health_check_is_plain(): void
    {
        $this->get('/up')->assertOk()->assertExactJson(['status' => 'ok']);
    }

    public function test_money_fields_reject_more_decimals_than_stored(): void
    {
        $this->actingAs($this->owner)
            ->postJson($this->api('/taxes/preview'), ['tariff' => '1000.123', 'nights' => 1, 'persons' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tariff'], 'error.fields');
    }
}
