# Winglet PHP Core 0.13.x Preparation

Date: 2026-11-07  
Repository: `wingletphp.core`  
Status: bounded implementation complete; workflow syntax validation and Base HTTP smoke remain unverified.

## Summary

Prepared Core for a subsequent 0.x release. The checked-out `main` HEAD is `69eba19`, tagged `v0.13.0-RC2`. The reported `v0.13.0-RC1` tag exists at `64ecc00`, so the checkout is one release candidate ahead of that reference. No commits, tags, pushes, or package publishing were performed.

## Changes

- Updated `composer.lock` within current manifest constraints. Compatible direct updates include Doctrine ORM 3.7.6, Monolog 3.12.1, PHPStan 2.3.5, PHPUnit 12.5.43, Rector 2.8.4, PHP_CodeSniffer 4.0.4, and Symfony Cache 7.4.23. Remaining direct updates are major-only (PHPUnit 13.4.6 and Symfony Cache 8.1.11), outside the declared constraints.
- Preserved legacy and canonical RS256 JWT validation. Grant-side `*` and namespace wildcards remain supported with case-sensitive matching; malformed wildcard demands remain denied. Canonical tenant/global context checks remain enforced.
- Privileges are accepted only as flat lists of nonempty strings. A malformed or mixed list yields no privileges. Legacy and old-standard `slv=0` tokens resolve as anonymous, and anonymous tokens no longer mark request log context authenticated.
- Role claims are ignored by token-derived authorization and never fall back into group checks. The existing deprecated `getRoles()` API and constructor slot remain for compatibility. No role-based authorization remains in Core. Repository-wide consumer usage outside the registered read/write scope is unknown.
- Added regression coverage for role-only claims, malformed privileges, `slv=0`, wildcard grants and demands, group OR / combined access AND behavior, and canonical tenant scoping.
- Added `.github/workflows/ci.yml` for push, pull-request, and `v*` tag test runs. It runs PHP 8.5, Composer validation/install/platform checks, syntax lint, PHPStan, PHPUnit, and the existing HTTP-CORS smoke script. It has read-only repository permissions and does not publish releases, notify Packagist, or expose publishing secrets.
- The pre-existing uncommitted indentation change in `bitbucket-pipelines.yml` was inspected and left intact.

## Files

- `.github/workflows/ci.yml`
- `composer.lock`
- `src/Auth/AuthContext.php`
- `src/Http/Middleware/AuthMiddleware.php`
- `src/Kernel/Bootstrap/Bootstrapper.php`
- `src/Security/AccessControl/AccessDecisionManager.php`
- `src/Security/Auth/Jwt/LegacyJwtTokenService.php`
- `src/Security/Auth/Jwt/StandardJwtTokenService.php`
- `tests/Security/Auth/AccessDecisionManagerSemanticsTest.php`
- `tests/Security/Auth/PrivilegeMatcherTest.php`
- `tests/Security/Auth/Jwt/CanonicalJwtConsumerFixtureTest.php`
- `tests/Security/Auth/Jwt/LegacyJwtTokenServiceTest.php`
- `tests/Security/Auth/Jwt/StandardJwtTokenServiceTest.php`
- `tests/Unit/Http/AuthMiddlewareTest.php`

## Verification

- `composer update --with-all-dependencies --no-interaction --prefer-dist --no-progress`: passed.
- `composer audit`: passed with exit code 0 and no reported advisories.
- `composer outdated --direct`: only major-version updates remain beyond current constraints.
- `composer validate --strict`: passed.
- `composer phpunit`: passed, 184 tests and 704 assertions. An earlier run caught a source syntax error during editing; it was corrected and the final run passed.
- `composer phpstan`: passed. An earlier run identified the deprecated compatibility accessor's unused return type and an obsolete helper; both were corrected before the passing run.
- `git diff --check`: passed after restoring LF line endings on edited files.
- The bounded catalog did not provide a YAML parser/check, so `.github/workflows/ci.yml` and the Bitbucket pipeline were reviewed structurally but not parser-validated.
- The Docker-marked HTTP-CORS smoke requires a separately approved host-permission gate and was not run in this task. The new GitHub Actions smoke job remains unexecuted here.

## Compatibility and release notes

The change intentionally removes roles as a token-derived authorization source. Applications that relied on roles-only route authorization will need to issue groups or privileges instead. The public `getRoles()` method and constructor parameter remain, but parsed legacy and standard role claims no longer populate them. No cross-repository consumer scan was possible within the authorized repository set.

Recommend the next release be a reviewed `v0.13.0` 0.x release after workflow YAML validation and a successful HTTP-CORS smoke against a Base image version completed by the Base owner. Bitbucket remains the delivery owner; Base integration is pending that updated Base completion.
