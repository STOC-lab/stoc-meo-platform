<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Tests must never touch a real database. phpunit.xml points them at an
     * in-memory SQLite connection, but a cached config file silently wins over
     * those environment variables, so verify where we actually landed before a
     * single test runs.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if (! in_array($database, [':memory:', 'stoc_meo_test'], true)) {
            $this->fail(
                "Refusing to run tests against [{$connection}: {$database}]. "
                .'Run `php artisan config:clear` and try again.'
            );
        }
    }
}
