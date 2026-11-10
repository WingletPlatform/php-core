#!/usr/bin/env bash
set -euo pipefail

# Lint all PHP files under src/ (adjust if needed)
TARGET_DIR="${1:-src}"

if [ ! -d "$TARGET_DIR" ]; then
  echo "Directory '$TARGET_DIR' not found. Adjust bin/ci/php-lint.sh TARGET_DIR."
  exit 1
fi

FAILED=0

while IFS= read -r -d '' file; do
  php -l "$file" >/dev/null || FAILED=1
done < <(find "$TARGET_DIR" -type f -name "*.php" -print0)

if [ "$FAILED" -ne 0 ]; then
  echo "PHP lint failed."
  exit 1
fi

echo "PHP lint OK."