<?php

/**
 * The suite pins the drivers it depends on before Laravel reads a single line
 * of configuration: a cache that starts empty for every test, and a queue that
 * runs inline.
 *
 * phpunit.xml declares them, but its <env force="true"> reaches only getenv()
 * and $_ENV, while Laravel's environment repository consults $_SERVER first. A
 * CI job or a shell exporting CACHE_STORE would otherwise quietly win, carrying
 * rate limiter counters between tests and swallowing queued notifications.
 */

require __DIR__.'/../vendor/autoload.php';

/*
 * A cached config file wins over every environment variable phpunit.xml sets,
 * so on a host that has run `php artisan config:cache` — a deployed one always
 * has — the suite would boot against the deployed database instead of the test
 * one. Pointing the cache at a path that is never written sends Laravel back to
 * reading config/ directly, without disturbing the cache the host is serving
 * from. This has to happen before the framework boots, which is why it lives
 * here rather than in TestCase::setUp().
 */
$_ENV['APP_CONFIG_CACHE'] = $_SERVER['APP_CONFIG_CACHE'] = __DIR__.'/../bootstrap/cache/config.testing-never-written.php';
putenv('APP_CONFIG_CACHE='.$_ENV['APP_CONFIG_CACHE']);

$drivers = [
    'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'array',
];

foreach ($drivers as $key => $value) {
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv("{$key}={$value}");
}
