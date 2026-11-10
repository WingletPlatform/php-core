# Identity harness secret permissions and cleanup

**Repository:** `wingletphp.core`  
**Date:** 2026-10-22

## Summary

Updated the Core-only Wing Identity HTTP integration harness to stage its generated HMAC test key with permissions the Apache PHP worker can read, while keeping it private to that worker. Bounded waits and ownership-checked cleanup address hangs and accidental cleanup of unrelated Docker resources.

## Changes

- The issuer still creates a random test key under a mode-0700 temporary directory and writes the key file as mode 0600.
- Core mounts that file read-only as a startup source. The container startup shell copies it to `/run/identity-test-key`, owned by `www-data:www-data` and mode 0400. The PHP configuration reads this private copy.
- Docker operations and HTTP calls have explicit timeouts. INT/TERM exit through the EXIT cleanup handler; container and image cleanup verifies a per-run label before removal. Failure logs remain bounded, and response headers/body are retained for diagnostics.
- Integration documentation now describes key ownership, expected malformed-token HTTP 401, rerun procedure, and manual cleanup using the exact resource names printed by the script.

## Files

- `scripts/wing-identity-core-http-integration.sh`
- `docs/Implementation/Wing-Identity-Core-HTTP-Integration-2026-10-22.md`
- `docs/Implementation/task-20261022-identity-harness-secret-cleanup-report.md`

## Verification

- `composer validate --strict`: passed through the bounded normal-test catalog.
- `composer phpunit`: passed through the bounded normal-test catalog.
- `composer phpstan`: failed before analysis because PHPStan could not create its cache directory under `var/phpstan/cache`; this does not identify a source diagnostic.
- The bounded catalog did not expose `bash -n`, so shell syntax parsing was unavailable under the task's verification restrictions.
- The Docker integration was not run in this Bridge environment. The expected 401 for malformed bearer input and 200 for a valid token remain to be confirmed by rerunning on a Docker-capable WSL host:

  ```sh
  bash scripts/wing-identity-core-http-integration.sh
  ```

## Deviations and risks

No production Core code, Base code, or Identity repository was changed. The harness assumes the Core runtime image starts its setup shell as root and uses the `www-data` account for Apache PHP workers; the script will fail during startup if those assumptions do not hold. No secret value is logged. If the host is forcibly killed before cleanup, remove only the container name, image name, and exact work directory printed by that run.

## Definition of done

The in-scope harness and documentation now implement the requested secret permissions, bounded cleanup, and diagnostic behavior. Unit/catalog checks passed as listed; Docker runtime behavior and shell syntax parsing remain unverified in this Bridge run.
