# Core PHP 8.5 CORS, PHPStan, and test runner report

Date: 2026-10-22  
Repository: `wingletphp.core`

## Summary

Fixed the CORS unit fixture to authorize its request origin, updated the PHP 8.5 verifier to run PHPStan and PHPUnit independently after prerequisites, and applied narrow PHPStan-oriented changes in the existing JWT implementation. Verification could not be run through the task's bounded catalog.

## Changes

- `tests/Unit/Http/CorsMiddlewareTest.php`: the default allow-headers case now explicitly allow-lists `https://app.example`. The reported null header is consistent with the current exact-origin policy because that fixture previously omitted `allow_origin`.
- `src/Security/Auth/Jwt/StandardJwtTokenService.php`: retained the existing logger constructor dependency and use it to record signature verification failure; removed the null-coalescing fallback when reading the JWKS provider's non-null `alg` field.
- `bin/ci/verify-php85.sh`: PHPStan and PHPUnit now both run after prerequisite checks and PHP linting, their individual statuses are printed and written to the summary artifact, and the verifier returns failure if either fails.
- `docs/php85-cors-migration.md`: recorded the fixes and verification limitations.

## Verification

- `list_normal_tests(repositoryId="wingletphp.core")`: returned an empty catalog. No tests were available through the bounded runner.
- PHPStan, PHPUnit, Composer, PHP CLI, Docker, and HTTP integration checks were not run. No test counts are claimed.
- The original PHPUnit warning (`testDefaultAllowHeadersUseCurrentRequestContextHeaders`, null haystack at the `assertStringContainsString` call) was not reproduced. Its nearby failure cause is explained by the missing allow-listed origin; the warning reported elsewhere in the handoff remains undiagnosed without `--display-warnings` output.

## Deviations and risks

- The working tree already contained broad CORS changes and a substantial uncommitted JWT rewrite before this task's edits. The rewrite was preserved; these changes do not establish that the full authentication behavior is unchanged.
- The PHPStan fixes have not been confirmed by PHPStan. The unit fixture has not been confirmed by PHPUnit.
- A changed-files-only ZIP was not created because the available bounded tool catalog has no archive creation capability and arbitrary host commands are disallowed for this task.

## Definition of done

CORS fixture and verifier changes are implemented, and the migration/testing documentation records exact verification limits. PHPStan/PHPUnit outcomes, warning diagnosis, full auth regression status, and archive delivery remain unverified or unavailable; release readiness is not established.
