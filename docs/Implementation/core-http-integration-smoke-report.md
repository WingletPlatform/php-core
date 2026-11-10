# Core HTTP smoke path and port diagnostics report

Date: 2026-10-22  
Repository: `wingletphp.core`  
Image default: `akikettu/wingletbase:v0.4.0-RC1`

## Summary

Corrected the Core HTTP smoke fixture's source mount and made container startup and port discovery failures distinguishable. The smoke script is now version 1.1.1.

## Root causes and changes

- Composer's generated optimized classmap in `vendor/composer/autoload_static.php` references Core files as `__DIR__ . '/../..' . '/src/...'`. With the vendor directory mounted at `/opt/core-vendor`, this resolves to `/opt/src/...`. The fixture previously mounted source at `/src`, so Composer could not load `Winglet\\Core\\Http\\Controller\\AbstractController`; route generation in `/tmp/build-routes.php` failed before Apache started. The container mount now maps `$CORE_ROOT/src` to `/opt/src` for both the existing-vendor and temporary-vendor paths.
- `docker run -d` uses `--publish 127.0.0.1::80/tcp`, which asks Docker to assign an ephemeral localhost port. A nonzero `docker run` now has a targeted daemon-rejection diagnostic while retaining Docker's own error text. The container touches a readiness marker only after route generation succeeds. The host polls that marker while inspecting container state, so an exited container fails immediately with a route-generation/startup diagnostic and the exit trap prints its logs. After the marker appears, a still-running container without valid localhost mapping gets a distinct metadata diagnostic. The assigned port is accepted only when it is numeric and within the valid TCP port range.
- Updated the migration guide to document the `/opt/src` classmap requirement and version 1.1.1 behavior.

## Files

- `scripts/http-integration-smoke.sh`
- `docs/php85-cors-migration.md`
- `docs/Implementation/core-http-integration-smoke-report.md`

## Verification

- Static source evidence: `vendor/composer/autoload_static.php` maps `Winglet\\Core\\Http\\Controller\\AbstractController` to `/opt/core-vendor/composer/../..` plus `/src/Http/Controller/AbstractController.php`; normalized, that source is `/opt/src/Http/Controller/AbstractController.php`. The old `/src` mount did not provide it.
- The failure path was reviewed: the container command runs `php /tmp/build-routes.php`, touches `/var/www/var/.routes-ready` only on success, then runs `/usr/local/bin/start-services`. The host checks container exit state and waits for the marker before entering HTTP readiness checks.
- Docker runtime behavior was not exercised during this Bridge task. The smoke itself remains unverified until run on a Docker-capable host.
- ShellCheck is not installed in this environment. `bash -n` was not run because arbitrary host verification commands are unavailable for this task. The bounded normal-test catalog is checked separately; any unavailable syntax check remains unverified.

## Deviations and risks

- Docker daemon behavior, dynamic localhost port publication, and live HTTP assertions remain runtime acceptance items. On a Docker-capable host, run `./scripts/http-integration-smoke.sh`. If a failure needs further diagnosis, inspect the named container while the script is waiting, using `docker inspect --format '{{json .State}} {{json .NetworkSettings.Ports}}' <container-name>` and `docker port <container-name> 80/tcp`; on failure, the script also prints the container logs before cleanup.
- This change is limited to Core. No other repository was modified.

## Definition of done

The classmap source mount now matches Composer's generated path, and the port/startup failure modes have separate diagnostics. Static code and path evidence are documented; Docker runtime and shell syntax checks remain unverified here.
