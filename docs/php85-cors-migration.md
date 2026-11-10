# Winglet Core: PHP 8.5 and configurable CORS migration

Date: 2026-10-22

## PHP requirement and dependency state

- Composer now requires >=8.5 <8.6; Composer's platform is set to 8.5.0. This deliberately supports PHP 8.5 only until the framework explicitly validates a later minor version.
- The lockfile keeps the existing dependency selections; no package was upgraded for this migration. At the checked-in lock state, notable versions are Doctrine ORM 3.6.7, Monolog 3.10.0, Symfony Cache 7.4.15, PHPStan 2.2.7, and PHPUnit 12.5.33. Locked package PHP constraints inspected for Doctrine ORM and its dependencies accept PHP 8.5.
- PHP 8.4 application deployments are no longer supported by Composer metadata. Downstream services must upgrade their runtime to PHP 8.5 before requiring this Core version.
- The PHP platform/lock metadata and Composer content hash were changed from PHP 8.4 to 8.5 without resolving or upgrading packages. No dependency resolution or install was run here.

## CORS behavior and downstream configuration

- CORS stays disabled by default. Base no longer injects wildcard CORS headers or handles OPTIONS outside the Core request pipeline.
- Enabled CORS requires http.cors.allow_origin to be a list of exact HTTP(S) origins. Wildcards, the string "request", suffix matching, and single-string origin values do not authorize requests. The request Origin is compared exactly with configured entries.
- Credentials are emitted only for an exact allow-listed origin. Keep allow_credentials false unless browser credentials are needed. Preflight methods and each requested header must be present in the configured allow_methods / allow_headers lists. Rejected preflights return 403 without CORS allow headers.
- Preflight is handled before auth, tenancy, and route dispatch. Ordinary OPTIONS requests still reach route handling. Vary covers Origin and, for preflights, Access-Control-Request-Method and Access-Control-Request-Headers.
- Applications that depended on Base's permissive wildcard behavior or Core's prior wildcard default must add an explicit origin allow-list. Exact allow-lists may expose previously hidden frontend integration dependencies; inventory browser origins before rollout.

Example application override:

    return [
        'http' => [
            'cors' => [
                'enabled' => true,
                'allow_origin' => ['https://console.example.test'],
                'allow_methods' => ['GET', 'POST', 'OPTIONS'],
                'allow_headers' => ['Authorization', 'Content-Type', 'X-Request-Id'],
                'allow_credentials' => false,
                'max_age' => 300,
            ],
        ],
    ];

## Compatibility and verification checklist

1. Upgrade service and CI PHP runtime to 8.5; run Composer platform validation, Core checks, application unit/static checks, and downstream integration tests.
2. Verify composer validate --strict and composer check-platform-reqs in PHP 8.5.
3. Set each browser UI origin explicitly and inventory methods/headers used by browser clients. Test an allowed origin, a denied origin, a suffix lookalike origin, allowed/denied preflight methods and headers, credentials, ordinary OPTIONS, and disabled CORS.
4. Test successful API responses and exception responses for CORS headers, then build the Base image and verify that it does not add CORS headers independently.

## Verification status

- Static review of the existing middleware, pipeline order, configuration, Composer manifest, and lockfile was completed.
- Unit tests were updated for exact origins, denied lookalike origins, preflight method/header checks, and disabled mode. They have not yet run in this environment.
- Composer validation, dependency installation, PHPStan, PHPUnit, and an integrated Base/Core HTTP smoke test remain unverified.

## Bridge follow-up verification (2026-10-22)

- Read-only inspection reconfirmed `composer.json` requires PHP `>=8.5 <8.6`, sets `config.platform.php` to `8.5.0`, and defines `phpstan`, `phpunit`, and aggregate `test` scripts. Source review of `CorsMiddleware` and its unit test file shows exact configured-origin matching, disabled-mode header stripping, and preflight allow-list checks; this is not a test result.
- The bounded Bridge normal-test catalog returned no tests for `wingletphp.core`. No Composer, PHPUnit, PHPStan, or HTTP/CORS integration command was run. Core was not exercised under a built Base image.
- No migration-induced defect was confirmed from static inspection alone; no source fix was made during this follow-up. Authentication/JWT regressions and the wider route/middleware pipeline remain unverified.
- Docker permission and Base-to-Core integration remain unverified; follow up with the task-scoped builder and runtime integration checks before release.

## Repeatable PHP 8.5 verification

Run `./bin/ci/verify-php85.sh` with PHP 8.5.x, Composer 2, and `tee` available. All are mandatory; the verifier has no optional test/tool checks. It fails closed unless the runtime is PHP 8.5, validates Composer metadata, installs the checked-in lockfile, checks platform requirements, lints PHP source/tests/configuration, and runs PHPStan and PHPUnit. Logs and `summary.json` are written to `.verification/php85/`; set `WINGLET_VERIFY_ARTIFACT_DIR` to select another location.

Bitbucket default, pull-request, and version-tag pipelines call this entry point using the `composer:2` image. The runtime check prevents the mutable image tag from silently running checks on a different PHP minor version. Verification artifacts are retained; release notification runs only after all checks pass. A developer with Docker can run the same script in the Composer image with `docker run --rm -v "$PWD:/workspace" -w /workspace composer:2 php bin/ci/verify-php85.sh` (the PHP gate still applies), or use a native PHP 8.5/Composer installation.

Core's PHPUnit suite exercises the real request/middleware/kernel pipeline, including disabled CORS, allowed and denied origins, allowed/denied preflights, and CORS headers on handled error responses. This is an in-process integration test, not a live Apache/server test. **External HTTP integration remains a release gate:** run the portable app fixture from `scripts/http-integration-smoke.sh` through the Base image and verify allowed/denied/disabled preflights and error responses. The published image digest is not recorded, and the host-specific Docker smoke is not a portable Bridge check, so no CI gate is configured here. The preceding PHPStan/PHPUnit status was not independently verified during this task.

## PHP 8.5 verification follow-up (2026-10-22)

- The default allow-headers unit case now configures `https://app.example` as an allowed origin. With the exact-origin policy, an enabled CORS configuration without `allow_origin` correctly emits no CORS response headers; the previous test fixture therefore tested a denied origin while expecting an allowed-origin header.
- `bin/ci/verify-php85.sh` now runs PHPStan and PHPUnit independently after prerequisite validation and PHP linting, prints both results, and exits non-zero if either check fails. Its summary artifact records each check status.
- The JWT verification path retains its injected logger and uses it for signature-failure diagnostics. The JWKS provider's non-null `alg` field is read without a redundant null-coalescing fallback.
- The Bridge bounded test catalog returned no runnable tests for Core, so no PHPStan/PHPUnit run or test counts are available from this follow-up. The previously reported PHPUnit warning was not reproducible or diagnosable without a PHPUnit run; investigate with `--display-warnings` in the PHP 8.5 CI environment.

## Repeatable Core HTTP integration smoke

Core now includes version 1.1.1 of `scripts/http-integration-smoke.sh` for a real HTTP pass through Apache in Base. Run it from the Core repository root on a Docker-capable Linux/WSL host:

```sh
./scripts/http-integration-smoke.sh
```

The script defaults to the published `akikettu/wingletbase:v0.4.0-RC1` image. To exercise another compatible image explicitly, set `WINGLET_BASE_IMAGE=repository:tag`. It starts a container bound only to an ephemeral `127.0.0.1` port and reads the assigned port from Docker container metadata. When Composer is mounted at `/opt/core-vendor`, its generated Core classmap resolves source under `/opt/src`; the script mounts Core's source at that path. It removes the container and temporary fixture on exit. It uses Core's existing `vendor/autoload.php` when available; if Core dependencies are absent, it installs the locked production dependencies into a temporary directory using Composer in the selected Base image. No database or other application service is required. Docker command rejection, container exit during route generation or startup, and missing localhost port metadata have separate diagnostics.

The generated fixture routes requests through Core's actual bootstrap, router, middleware pipeline, exception handler, and response helpers. It checks route parameters, a handled 500 response, disabled-by-default CORS, an exact allowed origin and a denied origin, an accepted preflight and rejected origin/method/header preflights, an ordinary routed `OPTIONS`, the Core download helper's `X-Content-Type-Options: nosniff`, Apache's version-hidden `Server: Apache` header, and absence of `X-Powered-By`. On Docker/package-network errors, the script fails with a diagnostic and still cleans up its resources.

## HTTP smoke result and remaining acceptance

- Implementation is present; **the HTTP smoke was not run during this Bridge task**. Do not treat it as a passing runtime result until executed against the image and the output reports `PASS`.
- The preceding Composer platform, PHPStan, and PHPUnit results reported in the handoff remain handoff-reported and were not independently rerun here.
- This fixture validates Core and Base HTTP integration only. Identity token, privilege/role, and session behavior still requires the downstream Identity end-to-end acceptance suite before release.
