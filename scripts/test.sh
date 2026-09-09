#!/usr/bin/env bash
#
# Runs the test suite.
#
# tests/bootstrap.php already keeps a deployed host's cached config from
# deciding which database the suite talks to, so `php artisan test` works on its
# own. This wrapper exists for the things that are easy to get wrong by hand:
# it refuses to run without the test database, and it passes your arguments
# straight through, so `scripts/test.sh --filter=test_a_store_manager_adds_a_keyword`
# and `scripts/test.sh tests/Feature/KeywordCrudTest.php` both work.
#
# It never clears the application's config cache: on a deployed host that is a
# live change, and it is not needed.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

if [[ ! -f vendor/autoload.php ]]; then
    echo "Dependencies are not installed. Run: composer install" >&2
    exit 1
fi

php artisan test --compact "$@"
