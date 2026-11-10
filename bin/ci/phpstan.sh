#!/usr/bin/env bash
set -euo pipefail

if [ ! -x ./vendor/bin/phpstan ]; then
  echo "PHPStan not found. Add as dev dependency: composer require --dev phpstan/phpstan"
  exit 1
fi

# Use config file if present; otherwise run a sensible default.
if [ -f phpstan.neon ] || [ -f phpstan.neon.dist ]; then
  ./vendor/bin/phpstan analyse
else
  ./vendor/bin/phpstan analyse -l 6 src
fi