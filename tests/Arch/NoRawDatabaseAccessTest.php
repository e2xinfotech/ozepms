<?php

namespace Tests\Arch;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * All database access goes through the single configured connection.
 * Raw drivers or extra connections are only allowed in app/Infrastructure/Database.
 */
class NoRawDatabaseAccessTest extends TestCase
{
    public function test_application_code_does_not_open_its_own_database_connections(): void
    {
        $root = dirname(__DIR__, 2).'/app';
        $allowed = $root.'/Infrastructure/Database';
        $patterns = ['/new\s+\\\\?PDO\s*\(/', '/\bmysqli_?\w*\s*\(/', '/DB::connection\s*\(/', '/new\s+\\\\?mysqli\b/'];
        $violations = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || str_starts_with($file->getPathname(), $allowed)) {
                continue;
            }
            $code = file_get_contents($file->getPathname());
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $code)) {
                    $violations[] = str_replace($root, 'app', $file->getPathname());
                }
            }
        }

        $this->assertSame([], $violations, 'Raw database access found in: '.implode(', ', $violations));
    }
}
