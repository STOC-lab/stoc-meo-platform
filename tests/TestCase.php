<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Tests must never touch a real database. phpunit.xml points them at
     * stoc_meo_test and tests/bootstrap.php keeps a deployed host's cached
     * config from overruling it, but both are only arrangements; this checks
     * where the suite actually landed before a single test runs.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if (! in_array($database, [':memory:', 'stoc_meo_test'], true)) {
            $this->fail(
                "Refusing to run tests against [{$connection}: {$database}]. "
                .'tests/bootstrap.php points APP_CONFIG_CACHE at a file that is never '
                .'written so a cached config cannot win; check what has overridden it, '
                .'and do not clear the cache of a deployed host to get past this.'
            );
        }

        $this->assertIsolatedDrivers();
    }

    /**
     * The suite assumes a cache that starts empty for every test and a queue
     * that runs inline: a durable cache carries rate limiter counters from one
     * test into the next, and a real queue connection swallows the notifications
     * tests expect to have been delivered. Both are set in phpunit.xml, and both
     * are easy to lose to an exported environment variable, so say so plainly
     * rather than leaving a puzzling failure several tests later.
     */
    protected function assertIsolatedDrivers(): void
    {
        $expected = [
            'cache.default' => 'array',
            'queue.default' => 'sync',
        ];

        foreach ($expected as $key => $want) {
            $actual = config($key);

            if ($actual !== $want) {
                $this->fail(
                    "Tests need [{$key}] to be [{$want}], but it is [{$actual}]. "
                    .'Something in the environment is overriding phpunit.xml.'
                );
            }
        }
    }
}
