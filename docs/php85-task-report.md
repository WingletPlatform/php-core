# Core PHP 8.5 and CORS task report

Date: 2026-10-22

## Summary

Set the Core Composer platform to PHP 8.5 and changed CORS from wildcard-capable configuration to disabled-by-default exact-origin allow-list behavior. See [php85-cors-migration.md](php85-cors-migration.md) for package versions and downstream migration steps.

## Changes

- `composer.json` requires `>=8.5 <8.6` and sets the platform to `8.5.0`; `composer.lock` platform metadata and content hash were updated without changing locked package selections.
- CORS remains disabled by default. When enabled, exact HTTP(S) origins are required; wildcard/suffix matching is unavailable. Preflight methods and requested headers are checked, denied requests receive no CORS allow headers, and `Vary` covers relevant request fields.
- Added unit cases for disabled/denied header stripping, allow-listed origins, wildcard rejection, suffix lookalikes, successful preflights, method rejection, and normal OPTIONS routing.
- Updated HTTP configuration documentation and added migration guidance.

## Files in this repository's delivery bundle

- `composer.json`
- `composer.lock`
- `docs/Configuration/HttpConfiguration.md`
- `docs/php85-cors-migration.md`
- `resources/config/20_http.php`
- `src/Http/Middleware/CorsMiddleware.php`
- `tests/Unit/Http/CorsMiddlewareTest.php`

Unrelated pre-existing JWT/auth changes remain in the working tree and were not included.

## Verification

- Static review confirmed Core middleware is placed before auth, tenant resolution, and routing, and wraps error handling so application exception responses pass through CORS processing.
- Bounded normal-test catalog: no Core tests available.
- PHPUnit, PHPStan, Composer validation/install, and integrated HTTP smoke tests: not run.
- Cross-repository image verification was not run.

## Deviations and risks

- PHP 8.4 consumers must upgrade before adopting this Core requirement.
- Exact origin allow-lists can break clients that relied on prior wildcard defaults; downstream services must configure browser origins, methods, and headers explicitly.
- Dependency selections are unchanged; compatibility was inspected from the lock metadata but not exercised under PHP 8.5.

## Definition of done

- Core Composer PHP 8.5 metadata, secure configurable CORS behavior, tests, and migration guidance are implemented.
- Runtime/unit/static checks remain unverified. No deployment, push, or commit was performed.
