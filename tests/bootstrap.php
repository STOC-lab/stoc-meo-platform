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
