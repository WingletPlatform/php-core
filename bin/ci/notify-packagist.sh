#!/usr/bin/env bash
set -euo pipefail

: "${PACKAGIST_USERNAME:?missing PACKAGIST_USERNAME}"
: "${PACKAGIST_TOKEN:?missing PACKAGIST_TOKEN}"
: "${REPOSITORY_URL:?missing REPOSITORY_URL}"  # esim. https://bitbucket.org/vendor/repo

curl -sS -f \
  -X POST "https://packagist.org/api/update-package" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer ${PACKAGIST_USERNAME}:${PACKAGIST_TOKEN}" \
  -H "User-Agent: winglet-ci (mailto:aki@akikettu.net)" \
  -d "{\"repository\":\"${REPOSITORY_URL}\"}"