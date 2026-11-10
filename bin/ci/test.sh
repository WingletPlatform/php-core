#!/usr/bin/env bash
set -euo pipefail

php -v
composer --version

echo "==> composer validate"
composer validate --no-interaction --strict

echo "==> composer install"
composer install --no-interaction --prefer-dist --no-progress

echo "==> phpunit"
./bin/ci/phpunit.sh