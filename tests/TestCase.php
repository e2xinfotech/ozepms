<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\CreatesTenants;

abstract class TestCase extends BaseTestCase
{
    use CreatesTenants, RefreshDatabase;

    /** Reference data, permissions and system roles are needed by almost every test. */
    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\TestBaseSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['ozepms.security.require_2fa.platform_users' => false, 'ozepms.security.require_2fa.property_owners' => false]);
    }
}
