#!/usr/bin/env bash
set -euo pipefail

if [ ! -x ./vendor/bin/phpunit ]; then
  echo "PHPUnit not found. Add as dev dependency: composer require --dev phpunit/phpunit"
  exit 1
fi

# Prefer explicit config if present (common in libraries)
if [ -f ./phpunit.xml ]; then
  echo "Running PHPUnit using phpunit.xml"
  ./vendor/bin/phpunit --configuration ./phpunit.xml
  exit 0
fi

if [ -f ./phpunit.xml.dist ]; then
  echo "Running PHPUnit using phpunit.xml.dist"
  ./vendor/bin/phpunit --configuration ./phpunit.xml.dist
  exit 0
fi

# Fallback: run tests directory if it exists
if [ -d ./tests ]; then
  echo "Running PHPUnit using ./tests"
  ./vendor/bin/phpunit ./tests
  exit 0
fi

echo "No phpunit.xml(.dist) and no ./tests directory found."
echo "PHPUnit would print usage and fail. Commit tests/ or add phpunit.xml.dist."
exit 1