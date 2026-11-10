#!/usr/bin/env bash
set -euo pipefail

# ---- Adjust these defaults if your setup differs ----
: "${WINGLET_CONTAINER:=}"                # If set, scripts use `docker exec` directly
: "${WINGLET_SERVICE:=app}"               # docker compose service name
: "${WINGLET_WORKDIR:=/var/www/App}"      # project path inside container
: "${WINGLET_PHP:=php}"                   # php binary inside container
: "${WINGLET_COMPOSER:=composer}"         # composer inside container
: "${WINGLET_COMPOSE:=docker compose}"    # or "docker-compose" on older setups

# Resolve container ID/name:
resolve_container() {
  if [ -n "${WINGLET_CONTAINER}" ]; then
    echo "${WINGLET_CONTAINER}"
    return 0
  fi

  # Try docker compose ps for the service
  if ${WINGLET_COMPOSE} ps -q "${WINGLET_SERVICE}" >/dev/null 2>&1; then
    local cid
    cid="$(${WINGLET_COMPOSE} ps -q "${WINGLET_SERVICE}" | head -n 1 || true)"
    if [ -n "${cid}" ]; then
      echo "${cid}"
      return 0
    fi
  fi

  echo "Could not resolve container. Set WINGLET_CONTAINER=<container> or WINGLET_SERVICE=<compose-service>." >&2
  exit 1
}

exec_in_container() {
  local cid
  cid="$(resolve_container)"
  docker exec -t \
    -w "${WINGLET_WORKDIR}" \
    "${cid}" \
    "$@"
}