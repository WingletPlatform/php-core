# Core integration with Winglet Base v0.4.0-RC1

Date: 2026-10-22  
Repository: `wingletphp.core`  
Status: **integration started; live image and application acceptance remain pending**

## Summary and image mapping

Core is a Composer framework library. This repository has no Dockerfile, Compose application, or runtime image build to retarget. Its CI uses `composer:2` to install and verify the library, so replacing that CI image with Base would change the PHP development/test toolchain and is not appropriate. The Base release pipeline publishes version tags from the exact Git tag (`IMAGE_NAME=akikettu/wingletbase`, `IMAGE_TAG=${BITBUCKET_TAG}`). With the supplied release context, the exact expected image reference is:

```text
akikettu/wingletbase:v0.4.0-RC1
```

The tag mapping is unambiguous in Base's version-tag pipeline. Registry availability, digest, and architecture were not queried from this environment; confirm them with the host commands below before relying on the image. Do not substitute `latest`, `latest-dev`, or an invented tag. Record the resolved digest in the downstream service's release record.

## Core changes in this integration start

- The bundled minimal application's front controller no longer sends wildcard CORS headers or short-circuits every `OPTIONS` request before Core. This permits Core's default-off CORS behavior and request pipeline to decide the response.
- The example accepts `WINGLET_APP_PATH` so it can keep the app root at Base's `/var/www/App` when Apache serves the mounted `public` directory at `/var/www/html`. Without the variable, the existing local layout still uses the parent of `public`.
- No application image or CI image was changed. Base remains read-only and unchanged.

### Files changed in this task

- `examples/minimal-app/public/index.php`
- `docs/Implementation/Core-Base-v0.4.0-RC1-integration.md` (this migration/completion record)

The current Core Composer manifest requires PHP `>=8.5 <8.6`, sets the Composer platform to `8.5.0`, and has PHPStan and PHPUnit scripts. The Base repository records its upstream runtime as `php:8.5.12-apache-bookworm`; its packages and Composer installer are not fully version-pinned, so capture actual versions from the pulled image. The Core lockfile/package versions recorded in the existing migration notes include Doctrine ORM 3.6.7, Monolog 3.10.0, Symfony Cache 7.4.15, PHPStan 2.2.7, and PHPUnit 12.5.33.

## Security and behavior contract

- CORS is disabled unless the application explicitly enables `http.cors.enabled`.
- When enabled, configure exact HTTP(S) origins in `http.cors.allow_origin`; do not use `*`, `request`, suffix patterns, or a scalar origin. Keep credentials disabled unless required.
- Confirm Base does not independently add CORS headers, and check that Core handles preflights before auth, tenancy, and routes while ordinary `OPTIONS` continues through route handling.
- Validate auth token success/failure, privilege checks, roles, sessions, allowed and denied routes, secure application configuration, and handled error responses. Identity-specific behavior must pass the PHP Identity repository's end-to-end acceptance suite before the integration is considered accepted. That repository/runtime was not available in this task's registered repository context, so this gate is pending.
- The existing legacy application image migration may require PHP 8.5 and Composer-compatible application dependencies. Do not copy production secrets into an image; mount/inject environment-specific secure configuration at runtime.

## Host integration procedure

These commands are for a Docker-capable host. Set `APP_ROOT` to a prepared downstream application directory containing `vendor/autoload.php`, `public/index.php`, the Core configuration, and routes. The app's public directory is mounted at Base's Apache document root while the full application remains at `/var/www/App`.

```sh
IMAGE=akikettu/wingletbase:v0.4.0-RC1
APP_ROOT=/absolute/path/to/prepared/app
CORE_ROOT=/absolute/path/to/core/checkout
RUNTIME_ROOT=$(mktemp -d)
mkdir -p "$RUNTIME_ROOT/cache/doctrine" "$RUNTIME_ROOT/doctrine/proxies"

docker pull "$IMAGE"
docker image inspect "$IMAGE" --format '{{.RepoDigests}}'
docker run --rm --entrypoint sh "$IMAGE" -ec 'php -v && composer --version && php -m && apache2 -v'

# Run Core's PHPStan and PHPUnit independently inside the Base RC runtime.
docker run --rm --entrypoint sh -v "$CORE_ROOT:/var/www/Core:ro" -w /var/www/Core "$IMAGE" \
  -ec 'composer validate --strict && composer check-platform-reqs && composer phpstan'
docker run --rm --entrypoint sh -v "$CORE_ROOT:/var/www/Core:ro" -w /var/www/Core "$IMAGE" \
  -ec 'composer check-platform-reqs && composer phpunit'

docker run --rm --name winglet-core-base-rc1 \
  -p 127.0.0.1:8080:80 \
  -e WINGLET_APP_PATH=/var/www/App \
  -v "$APP_ROOT:/var/www/App:ro" \
  -v "$RUNTIME_ROOT:/var/www/App/var" \
  -v "$APP_ROOT/public:/var/www/html:ro" \
  "$IMAGE"
```

In another terminal, exercise the application's real endpoints (replace paths with routes supplied by the app). Capture status and headers for each request:

```sh
curl -i http://127.0.0.1:8080/health
curl -i -H 'Origin: https://allowed.example.test' http://127.0.0.1:8080/auth/me
curl -i -H 'Origin: https://denied.example.test' http://127.0.0.1:8080/auth/me
curl -i -X OPTIONS \
  -H 'Origin: https://allowed.example.test' \
  -H 'Access-Control-Request-Method: POST' \
  -H 'Access-Control-Request-Headers: Authorization, Content-Type' \
  http://127.0.0.1:8080/auth/me
curl -i -X OPTIONS \
  -H 'Origin: https://allowed.example.test' \
  -H 'Access-Control-Request-Method: DELETE' \
  http://127.0.0.1:8080/auth/me
```

Use the app's configured allowed method/header list for the successful preflight and an excluded method/header for the denied one. Also verify disabled CORS emits no allow headers, an ordinary application `OPTIONS` route is not swallowed, successful and denied token requests behave correctly, privilege/role gates return expected results, sessions preserve their configured cookie security attributes, unauthorized routes are denied, and exception responses follow the CORS policy. Stop the foreground container with Ctrl-C after capturing the responses, then remove the temporary runtime directory with `rm -rf "$RUNTIME_ROOT"`. Run the app's own PHPStan, PHPUnit, Composer platform checks, and Identity end-to-end suite against the same release candidate.

## Verification and result

| Check | Result in this task |
| --- | --- |
| Base tag mapping | Source-confirmed as `akikettu/wingletbase:v0.4.0-RC1` from Base's `v*` release pipeline plus supplied release context |
| Docker Hub tag existence/digest | Pending host verification; not queried here |
| Core HTTP integration on Base RC | Pending; Docker/runtime execution is unavailable through Bridge |
| CORS/auth/privileges/roles/sessions/routes | Source-level integration checklist only; HTTP behavior is pending |
| PHP 8.5 PHPStan/PHPUnit | Not run through the bounded catalog in this task. Handoff-reported prior results were 165 PHPStan files without errors and 158 PHPUnit tests/600 assertions; these were not independently rerun or verified here. |
| PHP Identity end-to-end acceptance | Pending; Identity repository/runtime was not available in the registered repositories |
| Security scan | Not run for the RC image; review the Base release scan artifact and record its result alongside the image digest |

The repository reports PHP 8.5.12 as Base's upstream runtime. Its existing Base security notes describe production PHP configuration, Apache hardening, signature-checked Composer installer, and a Trivy release scan of HIGH/CRITICAL findings with a gate for fixable CRITICAL findings. These are Base project records, not a new scan result for the pulled RC image. Resolve and record the digest, architecture, PHP/Composer versions, and scan artifact before deployment.

## Downstream legacy service checklist (start with Credits)

1. Inventory service Dockerfiles, Compose files, Apache document roots, PHP extensions, cron jobs, writable paths, and runtime environment/secrets.
2. Pin the tested Base RC digest; replace the service's old PHP base and check all required extensions and command-line tools.
3. Upgrade service runtime/CI to PHP 8.5, install the checked-in lockfile, and run Composer platform checks, PHPStan, and PHPUnit independently.
4. Remove application-level wildcard CORS and unconditional `OPTIONS` handlers. Configure exact allowed origins, methods, and headers in Core; leave CORS disabled where browser access is not needed.
5. Run the HTTP checks above plus successful/invalid auth token, privilege/role authorization, session-cookie flags, allowed/denied route, and error-response checks.
6. Run PHP Identity end-to-end tests and the service's own acceptance suite; record outcomes and remaining migration risks before deployment.
7. Review Base security-scan results and application configuration for secrets, debug mode, and writable-path assumptions.

## Definition of done

This integration start is complete as a source/documentation change: the example no longer bypasses Core CORS and can resolve the mounted Base application root, and the expected Base tag plus host acceptance procedure are recorded. The overall integration is **not accepted** until the registry digest, image scan, live HTTP checks, Core PHPStan/PHPUnit, downstream access/auth/session checks, and PHP Identity end-to-end suite pass and their results are recorded.
