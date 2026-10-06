<?php

namespace Tests\Feature\Pages;

use App\Domain\Auth\LoginService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Every Phase 1 page: who gets 200, who gets 403, and guests go to the login page. */
class PageAccessTest extends TestCase
{
    private const PROPERTY_PAGES = [
        'dashboard' => 'property/dashboard/index',
        'properties' => 'property/properties/index',
        'settings' => 'property/settings/index',
        'users' => 'property/users/index',
    ];

    private const ADMIN_PAGES = [
        '/admin' => 'admin/dashboard/index',
        '/admin/properties' => 'admin/properties/index',
        '/admin/properties/new' => 'admin/properties/create',
        '/admin/users' => 'admin/users/index',
        '/admin/plans' => 'admin/plans/index',
        '/admin/audit' => 'admin/audit/index',
        '/admin/system' => 'admin/system/index',
    ];

    public static function propertyAccess(): array
    {
        return [
            'owner' => ['owner', ['dashboard' => 200, 'properties' => 200, 'settings' => 200, 'users' => 200]],
            'hotel manager' => ['hotel_manager', ['dashboard' => 200, 'properties' => 200, 'settings' => 200, 'users' => 200]],
            'front desk' => ['front_desk', ['dashboard' => 200, 'properties' => 200, 'settings' => 200, 'users' => 403]],
            'housekeeping' => ['housekeeping', ['dashboard' => 200, 'properties' => 200, 'settings' => 200, 'users' => 403]],
        ];
    }

    #[DataProvider('propertyAccess')]
    public function test_property_pages_by_role(string $role, array $expected): void
    {
        [$property, $owner] = $this->createPropertyWithOwner();
        $user = $role === 'owner' ? $owner : $this->addMember($property, $role);

        foreach ($expected as $page => $status) {
            $response = $this->actingAs($user)->get("/p/{$property->code}/{$page}");
            $response->assertStatus($status);
            if ($status === 200) {
                $response->assertSee('"page":"'.str_replace('/', '\/', self::PROPERTY_PAGES[$page]).'"', false);
            }
        }
    }

    public function test_property_root_redirects_to_dashboard(): void
    {
        [$property, $owner] = $this->createPropertyWithOwner();

        $this->actingAs($owner)->get("/p/{$property->code}")->assertRedirect("/p/{$property->code}/dashboard");
    }

    public function test_admin_pages_for_super_admin(): void
    {
        [$property] = $this->createPropertyWithOwner();
        $admin = $this->makePlatformAdmin();

        foreach (self::ADMIN_PAGES + ["/admin/properties/{$property->code}/edit" => 'admin/properties/edit'] as $url => $page) {
            $this->actingAs($admin)->get($url)->assertOk()->assertSee('"page":"'.str_replace('/', '\/', $page).'"', false);
        }
        $this->actingAs($admin)->get('/admin/properties/P999999/edit')->assertNotFound();
        $this->actingAs($admin)->get('/home')->assertRedirect(route('admin.dashboard'));
    }

    public function test_admin_pages_are_forbidden_to_property_users(): void
    {
        [$property, $owner] = $this->createPropertyWithOwner();

        foreach (array_keys(self::ADMIN_PAGES) as $url) {
            $this->actingAs($owner)->get($url)->assertForbidden();
        }
        $this->actingAs($owner)->get("/admin/properties/{$property->code}/edit")->assertForbidden();
    }

    public function test_account_and_onboarding_pages(): void
    {
        [, $owner] = $this->createPropertyWithOwner();

        foreach (['/account/profile' => 'account/profile', '/account/security' => 'account/security', '/properties' => 'onboarding/picker', '/properties/new' => 'onboarding/create-property'] as $url => $page) {
            $this->actingAs($owner)->get($url)->assertOk()->assertSee('"page":"'.str_replace('/', '\/', $page).'"', false);
        }
    }

    public function test_guest_pages(): void
    {
        $this->get('/login')->assertOk()->assertSee('"page":"auth\/login"', false);
        $this->get('/forgot-password')->assertOk()->assertSee('"page":"auth\/forgot-password"', false);
        $this->get('/reset-password/some-token?email=a%40b.test')->assertOk()->assertSee('"page":"auth\/reset-password"', false);
        $this->get('/')->assertRedirect(route('login'));

        $this->withSession([LoginService::SESSION_PENDING_2FA => ['user_id' => 1, 'remember' => false, 'expires_at' => now()->addMinute()->timestamp]])
            ->get('/two-factor')->assertOk()->assertSee('"page":"auth\/two-factor"', false);
    }

    public function test_guests_are_redirected_from_every_signed_in_page(): void
    {
        [$property] = $this->createPropertyWithOwner();

        $urls = array_merge(
            array_map(fn ($p) => "/p/{$property->code}/{$p}", array_keys(self::PROPERTY_PAGES)),
            array_keys(self::ADMIN_PAGES),
            ['/home', '/account/profile', '/account/security', '/properties', '/properties/new'],
        );
        foreach ($urls as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    public function test_pages_never_expose_internal_ids_or_secrets(): void
    {
        [$property, $owner] = $this->createPropertyWithOwner();
        $owner->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP'])->save();

        $html = $this->actingAs($owner)->get("/p/{$property->code}/users")->assertOk()->getContent();

        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', $html);

        preg_match('#<script type="application/json" id="oz-page"[^>]*>(.*?)</script>#s', $html, $m);
        $payload = json_decode($m[1], true);
        $keys = [];
        $walk = function (array $node) use (&$walk, &$keys) {
            foreach ($node as $key => $value) {
                $keys[] = $key;
                if (is_array($value)) {
                    $walk($value);
                }
            }
        };
        $walk($payload['props']);
        $walk($payload['shell']);

        foreach (['password', 'two_factor_secret', 'two_factor_recovery', 'remember_token', 'user_id', 'property_id'] as $forbidden) {
            $this->assertNotContains($forbidden, $keys, "Page data must not contain \"{$forbidden}\".");
        }
    }

    public function test_responses_carry_security_headers_without_fingerprints(): void
    {
        $response = $this->get('/login');

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
        $this->assertFalse($response->headers->has('X-Powered-By'));
        foreach ($response->headers->getCookies() as $cookie) {
            $this->assertNotSame('XSRF-TOKEN', $cookie->getName());
            $this->assertStringNotContainsString('laravel', strtolower($cookie->getName()));
        }
    }

    public function test_health_check_is_plain_json_without_framework_page(): void
    {
        $response = $this->get('/up')->assertOk()->assertExactJson(['status' => 'ok']);

        $this->assertStringNotContainsString('Application up', $response->getContent());
        $this->assertSame([], $response->headers->getCookies());
    }
}
