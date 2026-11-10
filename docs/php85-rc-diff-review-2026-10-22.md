# Core PHP 8.5 RC candidate diff review

Date: 2026-10-22  
Repository: `wingletphp.core`  
Scope: final working-tree and release-scope review; Core only.

## Decision

**Recommend `v0.13.0-RC1` as the next Core RC candidate, subject to the checklist below.** The latest local tag is `v0.12.2`; existing historical RC tags use `v0.2.3-RC<n>`. A `0.13.0` prerelease is a reasonable next minor line for this pre-1.0 project: it groups the PHP 8.5 migration, CORS policy change, and additive auth/JWT consumer capabilities. The PHP 8.5-only Composer requirement is a deliberate compatibility break for PHP 8.4 consumers and must be prominent in release notes. No `version` field is set in Composer.

This is a recommendation only. The tag is not known to exist remotely: only local refs were inspected, and no network lookup was attempted. No commit, tag, push, deployment, or publication was performed.

## Worktree and diff inventory

`HEAD` is `06fd026` (`v0.12.2`, `origin/main`). The branch is `main...origin/main`. There were no staged paths. Before this report was added, `git status` listed 20 modified paths and 25 untracked files (including files under `docs/Implementation/`). The content diff had 13 tracked paths: 408 insertions and 423 deletions. `git diff --check` found no whitespace errors; Git emitted CRLF-to-LF normalization warnings for several modified files.

Seven paths show modified status but have the same blob hash as `HEAD` and no content diff: `bin/ci/check.sh`, `bin/ci/notify-packagist.sh`, `bin/ci/php-lint.sh`, `bin/ci/phpstan.sh`, `bin/ci/phpunit.sh`, `bin/ci/test.sh`, and `bin/dev/_docker-env.sh`. Their status should be rechecked before staging; do not stage them based on status alone.

The content changes cover Composer's PHP platform, CI verification entry points, HTTP/CORS defaults and middleware, the minimal app bootstrap, AuthContext, JWT/JWKS validation and privilege matching, tests, and migration/integration documentation. The lockfile changes its content hash and PHP platform metadata; no dependency package versions changed in the diff.

## Review findings and risks

- **Consumer compatibility:** `composer.json` now requires `>=8.5 <8.6`, excluding PHP 8.4. Applications relying on wildcard/string CORS origins must configure exact origin arrays. These changes are documented, but are release-visible breaking changes.
- **CORS:** Core defaults to disabled CORS and removes downstream `Access-Control-*` headers while disabled or when the request origin is not allow-listed. Enabled requests use exact origins, and preflights validate requested methods and headers before routing. Error responses are covered by the kernel test. This is a deliberate policy change; browser clients need an explicit migration.
- **JWT/profile selection:** The change adds canonical `winglet+jwt` RS256 handling, opaque string session subjects, service-principal tokens without `sid`, and a privilege matcher while preserving the explicit legacy parser path. No confirmed signature/profile defect surfaced in this review. The rewritten `StandardJwtTokenService.php` compresses substantial parsing and validation logic into dense one-line methods; expand it into the repository's normal readable style before a stable release so security review and future maintenance remain practical.
- **CI/release trigger:** Default, pull-request, and `v*` tag pipelines now use the PHP 8.5 verifier. The tag pipeline still notifies Packagist after verification, so an RC tag will enter that release pipeline. The verifier rejects non-8.5 runtimes, but the pipeline's `composer:2` image tag is mutable.
- **External evidence:** This review independently ran the bounded Composer, PHPUnit, and PHPStan checks (results below). The Docker/Base HTTP smoke and cross-issuer HTTP results were not rerun here. The handoff reports the Base/Core smoke and both Identity HTTP paths passed; those remain handoff/user-reported evidence, not results from this run. Tenant issuance, live Auth, database-backed lifecycle/revocation, key rotation, and downstream Credits acceptance remain outside the demonstrated global actor/service paths.
- **Remote tag state:** The configured `origin` was inspected locally, but remote tags were not queried. Availability of `v0.13.0-RC1` must be checked by the release operator before creating it.

## Recommended stage allowlist

No paths are staged now. For a release commit after checklist completion, stage only this reviewed set:

```text
.gitignore
bitbucket-pipelines.yml
composer.json
composer.lock
docs/Configuration/HttpConfiguration.md
docs/php85-cors-migration.md
docs/php85-rc-final-acceptance-2026-10-22.md
docs/php85-rc-diff-review-2026-10-22.md
docs/Implementation/Core-Base-v0.4.0-RC1-integration.md
docs/Implementation/Identity-Core-Token-Acceptance-2026-10-22.md
docs/Implementation/PHP-Identity-Compatibility-Acceptance-2026-10-22.md
docs/Implementation/Wing-Identity-Core-HTTP-Integration-2026-10-22.md
docs/Implementation/Winglet-Core-JWT-v1-Consumer-Report-2026-09-05.md
docs/Implementation/Winglet-Core-Wing-Identity-Migration-2026-10-22.md
docs/Implementation/WingletNet-Identity-Core-JWT-Interoperability-2026-10-22.md
docs/Implementation/core-http-integration-smoke-report.md
examples/minimal-app/public/index.php
resources/config/20_http.php
src/Auth/AuthContext.php
src/Http/Middleware/CorsMiddleware.php
src/Security/AccessControl/PrivilegeMatcher.php
src/Security/Auth/Jwt/JwksProvider.php
src/Security/Auth/Jwt/StandardJwtTokenService.php
tests/Kernel/HttpKernelTest.php
tests/Unit/Http/CorsMiddlewareTest.php
tests/Security/Auth/Jwt/CanonicalJwtConsumerFixtureTest.php
tests/Security/Auth/Jwt/WingIdentityLegacyTokenFixtureTest.php
tests/Security/Auth/Jwt/WingletNetIdentityCanonicalFixtureTest.php
bin/ci/verify-php85.sh
scripts/http-integration-smoke.sh
scripts/wing-identity-core-http-integration.sh
```

Keep these out of that release stage set unless separately reconciled: the seven status-only paths listed above; `docs/php85-task-report.md` and `docs/php85-verification-report.md` (interim records whose verification statements conflict with the later acceptance evidence and this run); `docs/http-integration-smoke-report.md` (duplicate smoke report); and `docs/Implementation/bridge-php85-cors-jwt-runner-report.md` plus `docs/Implementation/task-*.md` (task execution records rather than release documentation). Do not use blanket staging.

## Release checklist

1. Recheck `git status`, and confirm the seven status-only paths remain blob-identical. Review the exact staged diff after using the allowlist.
2. Reformat `StandardJwtTokenService.php` into readable, reviewable methods; rerun the bounded PHPStan and PHPUnit checks after that edit.
3. Confirm downstream services and their CI have moved to PHP 8.5 and migrated CORS origins, methods, and headers.
4. On a Docker-capable host, rerun `scripts/http-integration-smoke.sh` against the selected Base image; record its digest and runtime version. The handoff's prior pass is useful context but is not a fresh check here.
5. Preserve the reported legacy PHP Identity and .NET canonical HTTP results with their stated limits. Complete tenant/live Auth, session lifecycle/revocation, JWKS rotation, and Credits acceptance before production rollout.
6. Check remote tag availability and confirm the Packagist notification behavior for an RC tag. Only then create `v0.13.0-RC1` if the release owner approves it.

## Verification

- `composer validate --strict`: **PASS** (`composer.json` valid).
- `composer phpunit`: **PASS** on PHP 8.5.9; PHPUnit 12.5.33; 166 tests, 661 assertions.
- `composer phpstan`: **PASS**, no errors.
- `git diff --check`: **PASS**.
- Docker smoke: **not run**; no Docker gate was available through the bounded catalog. The handoff reports a prior local pass.

## Deviations, risks, and completion

This was a review, not an implementation or release action. The only new file is this completion report. Existing user work was left intact. No read-only repositories were inspected or modified. Release readiness is suitable for an RC candidate after the explicit checklist, but production acceptance is not established by this Core review.
