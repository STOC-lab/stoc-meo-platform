# Testing

## Never clear a deployed host's config cache to run tests

`tests/bootstrap.php` points `APP_CONFIG_CACHE` at a file that is never
written, which sends Laravel back to reading `config/` directly. This is what
makes `php artisan test` work on a host that has run `php artisan config:cache`
— a deployed one always has — without touching the cache it is serving from.

`php artisan config:clear` on a deployed host is a live change to a running
application. It is not the way past a test failure. If `TestCase` refuses to
run, find what overrode `APP_CONFIG_CACHE` instead.

The fix has to happen before the framework boots, so it belongs in
`tests/bootstrap.php`, not in `TestCase::setUp()` — by the time `setUp()` runs,
`parent::setUp()` has already booted the application and read the config.

## The suite pins its own environment

`phpunit.xml` forces `CACHE_STORE`, `SESSION_DRIVER`, `QUEUE_CONNECTION` and
`APP_TIMEZONE`, and `tests/bootstrap.php` re-pins the drivers into `$_SERVER`
because Laravel's environment repository consults it before `getenv()`. A
durable cache carries rate limiter counters between tests; a real queue
connection swallows the notifications tests assert were delivered; an inherited
timezone moves every date under test. Do not remove `force="true"`.

## Database

Tests run on MySQL against `stoc_meo_test`, not SQLite, to match CI.
`TestCase::setUp()` fails the run if it finds itself anywhere else.

## Running

- `php artisan test --compact`, or `scripts/test.sh` — both take a file path or
  `--filter=testName`, and `scripts/test.sh` passes arguments straight through.
- Run the narrowest set that covers the change; run it again after each edit.
- Create tests with `php artisan make:test --phpunit {name}`.
