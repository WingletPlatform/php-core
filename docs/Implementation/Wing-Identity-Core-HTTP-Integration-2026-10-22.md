# Wing Identity to Core HTTP integration test notes v1.2.0

**Date:** 2026-10-22  
**Write repository:** `wingletphp.core`  
**Identity source revision:** `wing-identity`, pinned commit `d821214facf8fd4856e234261ac309a0f390c711`, Composer `2024.1`  
**Core runtime image default:** `akikettu/wingletbase:v0.4.0-RC1`  
**Identity Dockerfile base:** `akikettu/wingng:2024.1-RC7`  
**Result:** The source-authentic token-to-HTTP harness is implemented. It has not been executed in this Bridge run because Docker host execution is unavailable here.

## Version 1.2.0

The harness no longer requires the Identity checkout's current `HEAD` to match the pinned commit and does not inspect or reject its working-tree changes. It verifies that the configured source is a Git checkout containing commit `d821214facf8fd4856e234261ac309a0f390c711`, then exports only that commit's `Dockerfile` and `Applications/Identity` tree into the harness-owned private temporary directory. The Dockerfile copies `Applications/Identity/` to `/var/www/Applications/Identity/`, and the token issuer mounts that same exported tree read-only. Both build and runtime therefore use only tracked files from the pinned commit; uncommitted Identity edits are intentionally excluded.

The pinned commit export is checked for the Dockerfile, `Session.php`, and `Identity.php` before Docker runs. The export directory is removed through the existing bounded EXIT cleanup path. Docker daemon availability is also checked with a finite timeout so an unavailable daemon does not leave the script waiting indefinitely.

`WING_IDENTITY_SOURCE` still selects where the Git repository is located. It is not treated as source-content authority: Docker receives the pinned Git archive, never the checkout's live tree. No Identity files or Git state are changed by this procedure.

## Version 1.1.0

The harness keeps the generated HMAC key in the private temporary directory as mode `0600`, then mounts it read-only as a startup source. Before Apache starts, the root startup shell copies it into the container as `/run/identity-test-key`, owned by `www-data:www-data` with mode `0400`. The PHP worker can read it, while other container users and host users cannot read the host source. The application configuration reads only the worker-owned copy. The key is never printed or included in request diagnostics.

Docker build/run/inspect/log/remove operations, token helper containers, and HTTP requests now have finite timeouts. The EXIT trap owns cleanup; Ctrl+C and TERM select conventional nonzero exit codes and then use that same cleanup path. Failure diagnostics include response headers/body and recent Apache/container logs. Container and image names include a per-run suffix, and cleanup removes only resources created by that invocation. The script prints the temporary resource names and work directory so a forced kill can be cleaned up manually.

Rerun from the Core repository root on a Docker-capable host:

```sh
bash scripts/wing-identity-core-http-integration.sh
```

Expected route semantics remain: missing or invalid authentication is 401; a valid identity with insufficient level or role is 403.

Malformed bearer input is expected to return HTTP 401. On failure, the script reports that response's headers and body, then bounded container and Apache logs. A permission warning for `/run/identity-test-key` means the runtime image did not provide the expected `www-data` worker account/ownership; no secret value is emitted in diagnostics.

## Summary

Added a reproducible local integration harness that builds Wing Identity's existing Dockerfile from the pinned commit archive, calls the actual `Session::getToken()` method on isolated test session objects, and sends those tokens through Core's HTTP pipeline using the explicit legacy token profile. This closes the gap between a hand-built synthetic token fixture and Core HTTP behavior without claiming a DB-backed Identity login.

The Identity source confirms that persisted login is implemented by Identity controllers and the session store. The harness does not boot that database stack. It sets fabricated test values on an unconstructed `Session` object, uses `Session::setState()` for the closed-state scenario, and invokes the actual token method. This exercises the real Identity encoding/signing code while keeping production services and secrets out of the run.

## Changes

- Added `scripts/wing-identity-core-http-integration.sh`.
- Updated `docs/Implementation/Winglet-Core-Wing-Identity-Migration-2026-10-22.md` to v1.1.0.
- Updated `docs/Implementation/PHP-Identity-Compatibility-Acceptance-2026-10-22.md` to v1.2.0.
- Added this implementation report.
- No Core production source, Identity repository, or Base repository was modified.

The harness generates a random 256-bit test-only HMAC key inside the temporary Identity container, writes it to a mode-0600 file in a mode-0700 temporary directory, and mounts it read-only as a startup source. A private container copy is assigned to `www-data` at mode `0400` for PHP to read. It removes temporary files, its uniquely named container, and its per-run image on exit. It does not source `.env` files or read a production secret. Core is configured with only `authentication.jwt.mode=legacy`.

## HTTP cases

| Case | Expected Core HTTP result | Evidence / meaning |
| --- | ---: | --- |
| Missing bearer token | 401 | Protected level route requires authentication. |
| Malformed bearer token | 401 | Core rejects the malformed legacy token shape. |
| Actual Identity token, level 100 | 200 | Identity ID and session ID are returned from Core's validated auth context. |
| Altered signature | 401 | HMAC validation rejects the token. |
| Token signed with a different test key | 401 | Demonstrates the issuer/consumer shared-secret mismatch failure using test-only keys. |
| Expired token from Identity method | 401 | Core rejects the emitted date-string `exp`. |
| Actual Identity token, level 10 | 403 | Minimum level 100 is denied. |
| Actual token on role-protected route | 403 | Identity token has no role/group grant. |
| Token issued from session state 901 | 200 until `exp` | Same claims produce the same token; Core cannot observe close state. |

The final row deliberately demonstrates the current contract: `Session::getToken()` does not encode state, and Core's legacy parser does not introspect Identity's session database. A successful DB-backed close changes Identity state to 901, but this consumer continues accepting an already-issued token until expiry. No immediate revocation claim is made.

## Exact run step

From the Core repository root on a Docker-capable WSL host:

```sh
bash scripts/wing-identity-core-http-integration.sh
```

The script expects a Git Identity checkout at `../../archived/wing/identity` that contains commit `d821214facf8fd4856e234261ac309a0f390c711`. Set `WING_IDENTITY_SOURCE` if the repository lives elsewhere. The checkout may have local changes: the harness ignores them and builds only the pinned commit's Dockerfile and Identity application tree. It uses `WINGLET_BASE_IMAGE` to select another Core runtime image. The Identity build uses the base specified by the pinned Dockerfile.

If a forced host kill leaves resources behind, use only the exact names printed by that run:

```sh
docker rm -f wing-identity-core-http-<run-suffix>
docker image rm wing-identity-core-http-test:20261022-<run-suffix>
rm -rf -- '<printed-workdir>'
```

Replace each placeholder with the exact container name, image name, and absolute temporary directory printed by that run. Do not copy a resource name from another run.

## Verification

- Inspected Identity `Session::getToken()`, `createTokenPayload()`, `setState()`, `Login`, `Sessions/CreateAndLogin`, `Sessions/Close`, and `Sessions/Check` source read-only.
- Inspected Core's legacy token parser, auth middleware, routing/access attributes, bootstrap mode selection, and existing HTTP smoke setup.
- `composer validate --strict` and `composer phpunit` passed through the bounded normal-test catalog.
- `composer phpstan` could not create its cache directory under `var/phpstan/cache` and failed before source analysis. The bounded catalog did not expose `bash -n`.
- The Docker integration was not run in this Bridge environment. No runtime result, image digest, PHP runtime version, or DB lifecycle result is claimed.

## Remaining acceptance work and risks

1. Run the exact command above on Docker-capable WSL and retain its PASS output. The Identity base tag is mutable; record image digests and PHP versions for release evidence.
2. Exercise actual Identity HTTP login and database-persisted close in a disposable test database if acceptance requires proof of the full service lifecycle. Do not point the harness at production data.
3. Confirm accepted logout latency. With current Core parsing, a closed Identity session remains usable until token expiry unless an application adds an approved introspection/revocation boundary.
4. Review request/security logs for bearer token and secret leakage in the target runtime.
5. Complete the downstream Credits checklist in `PHP-Identity-Compatibility-Acceptance-2026-10-22.md`, especially the decision whether session level alone is sufficient; roles and privileges are not present in the Identity token.

## Definition of done

The Core-only implementation and exact run procedure are reviewable. Source-authentic Identity method execution and Core HTTP outcomes are covered by the harness, with database-backed login/close clearly out of scope. Runtime execution and the listed release acceptance items remain open.

## Version 1.2.0 task report

### Summary

The harness builds from an isolated export of the pinned Wing Identity Git revision even when the located checkout has uncommitted changes. It does not modify or clean the Identity checkout.

### Changes and files

- Updated `scripts/wing-identity-core-http-integration.sh` to verify the pinned commit and expected tracked inputs, archive only its Dockerfile and Identity application tree, and use that export as the Docker build context and read-only token-issuer source.
- Updated this versioned integration report with the archive behavior, local-edit exclusion, and current verification results.

### Verification

- Bounded `composer phpunit`: passed (163 tests, 639 assertions).
- Bounded `composer validate --strict`: passed.
- Bounded `composer phpstan`: the runner accepted the attempt, but status lookup returned a missing gate record; the result is unknown.
- `bash -n`: unavailable through the bounded verification catalog; arbitrary host commands are not permitted for this task.
- Docker integration: not run in Bridge, as instructed. Docker runtime behavior remains unverified here.

### Deviations, risks, and DoD

No Identity or Base repository was modified. This version intentionally ignores uncommitted Identity edits and uses only paths from the specified pinned commit; the archive still requires that commit to exist in the selected local Git object database. Runtime validation on a Docker-capable host remains outstanding. The requested Core harness and versioned documentation update are complete; no commit, push, or deployment was performed.
