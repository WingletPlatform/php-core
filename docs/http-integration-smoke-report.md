# Core HTTP integration smoke fixture report

## Summary

Updated the Core HTTP integration smoke fixture to keep Base-owned writable runtime files inside the disposable Docker container. This prevents host cleanup from trying to remove files owned by the container's `www-data` user and avoids sudo, broad permissions, and changes to Core runtime behavior or Base.

## Changes

- Removed the host temporary runtime directory and its `/var/www/var` bind mount.
- Run Base's existing `setup-runtime-dirs` before generating routes, then continue with the normal service startup.
- Poll the readiness marker inside the container with `docker exec`.
- Bumped the script version from 1.1.1 to 1.1.2.
- Kept all existing route, HTTP security, ordinary OPTIONS, and CORS assertions unchanged.

## Files

- `scripts/http-integration-smoke.sh`
- `docs/http-integration-smoke-report.md`

## Verification

- Reviewed Base's `setup-runtime-dirs`: it assigns `/var/www/var` and its contents to `www-data`, matching the reported bind-mount cleanup failure.
- No bounded normal tests were available for `wingletphp.core`; no Docker or arbitrary host verification command was run.
- The handoff reports that both the CORS-disabled and exact-origin CORS-enabled applications started before the cleanup error. It does not establish whether all assertions in the second run completed. This change has not been rerun, so the fix and HTTP/CORS behavior remain runtime-unverified.

## Deviations and risks

- None. No Base or Core runtime code was changed, and no commit or push was made.
- Runtime verification remains outstanding. Rerun from the Core repository root with `./scripts/http-integration-smoke.sh`.

## Definition of done

- The fixture no longer exposes its writable runtime tree to host cleanup.
- The existing HTTP and CORS assertions remain present.
- The required report is present. Runtime success is not claimed.
