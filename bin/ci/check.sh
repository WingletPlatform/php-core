#!/usr/bin/env bash
set -euo pipefail

php -v
composer --version

echo "==> composer validate"
composer validate --no-interaction --strict

echo "==> composer install"
composer install --no-interaction --prefer-dist --no-progress

echo "==> PHP lint (php -l)"
./bin/ci/php-lint.sh

echo "==> PHPStan"
./bin/ci/phpstan.sh

echo "==> PHPUnit"
./bin/ci/phpunit.sh