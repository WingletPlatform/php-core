#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT_DIR"

for tool in php composer tee; do
    if ! command -v "$tool" >/dev/null 2>&1; then
        printf 'Required tool is missing: %s\n' "$tool" >&2
        exit 2
    fi
done

ARTIFACT_DIR="${WINGLET_VERIFY_ARTIFACT_DIR:-$ROOT_DIR/.verification/php85}"
mkdir -p "$ARTIFACT_DIR"
LOG_FILE="$ARTIFACT_DIR/verify.log"
SUMMARY_FILE="$ARTIFACT_DIR/summary.json"
exec > >(tee "$LOG_FILE") 2>&1
current_step='starting'
phpstan_status='not_run'
phpunit_status='not_run'
on_exit() {
    status=$?
    if [[ "$status" -eq 0 ]]; then
        result=passed
    else
        result=failed
    fi
    printf '{"status":"%s","exit_code":%s,"failed_step":"%s","php":"%s","phpstan":"%s","phpunit":"%s","http_integration":"unit-and-kernel-tests; external-server-smoke-not-configured"}\n' \
        "$result" "$status" "$current_step" "$(php -r 'echo PHP_VERSION;')" "$phpstan_status" "$phpunit_status" > "$SUMMARY_FILE"
}
trap on_exit EXIT

current_step='PHP 8.5 runtime'
php -v
php -r 'if (PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600) { fwrite(STDERR, "Verification requires PHP 8.5.x; got " . PHP_VERSION . "\n"); exit(1); }'

current_step='Composer version and manifest validation'
composer --version --no-ansi
composer validate --no-interaction --strict

current_step='locked dependency installation and platform requirements'
composer install --no-interaction --prefer-dist --no-progress --no-scripts
composer check-platform-reqs

current_step='PHP syntax lint'
for target in src tests resources/config bin; do
    if [[ -d "$target" ]]; then
        ./bin/ci/php-lint.sh "$target"
    fi
done

current_step='PHPStan'
if ./bin/ci/phpstan.sh; then
    phpstan_status='passed'
else
    phpstan_status='failed'
fi

current_step='PHPUnit'
if ./bin/ci/phpunit.sh; then
    phpunit_status='passed'
else
    phpunit_status='failed'
fi

current_step='summary'
printf 'PHPStan: %s\nPHPUnit: %s\n' "$phpstan_status" "$phpunit_status"
if [[ "$phpstan_status" != 'passed' || "$phpunit_status" != 'passed' ]]; then
    echo 'Core PHP 8.5 verification failed.'
    exit 1
fi

echo 'Core PHP 8.5 verification passed. External-server HTTP smoke remains a separate integration gate.'
