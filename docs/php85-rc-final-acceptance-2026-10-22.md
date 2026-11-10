# Winglet PHP Core 8.5 RC final acceptance

**Date:** 2026-10-22  
**Repository:** `wingletphp.core`  
**Decision:** **Ready for a PHP 8.5 RC candidate.** Core checks and the two reported Identity-to-Core HTTP paths pass within their stated scopes. This is not production or downstream Credits acceptance; follow-ups below remain open.  
**Write scope:** Core only. No commit, tag, push, publish, or deploy was performed.

## Acceptance evidence

### Bridge-run Core checks

All three bounded checks passed on PHP 8.5.9 with the checked-in lockfile and current working tree:

| Check | Result |
| --- | --- |
| `composer phpunit` | PASS — PHPUnit 12.5.33; 166 tests, 661 assertions |
| `composer phpstan` | PASS — PHPStan completed analysis of 168 files with no errors |
| `composer validate --strict` | PASS — `composer.json` valid |

These are Bridge-run results. They do not include a fresh Base-image HTTP run or a dependency reinstall/platform-requirement check inside the published image.

### User-confirmed archived PHP Wing Identity legacy HTTP v1.2.0

The user confirmed this WSL result for the checked-in `scripts/wing-identity-core-http-integration.sh` v1.2.0:

> PASS: Identity Session::getToken() -> Core legacy HTTP integration completed. Observed: valid 200; missing/invalid signature/expired 401; low level/role 403; closed-state token 200 until exp.

The fixture pins archived PHP Wing Identity at `d821214facf8fd4856e234261ac309a0f390c711`. This exercises the legacy token profile with Core explicitly configured for `legacy`; it is not .NET canonical JWT evidence and does not exercise database-backed login or close. This is user-confirmed evidence, not a Bridge-run check.

### User-confirmed .NET Winglet Identity canonical HTTP

The user confirmed a WSL integration run that called the actual `IdentityService.IssueAsync` and `ServicePrincipalService.IssueAsync` methods with disposable MariaDB and a test-only Auth client, then passed the issued tokens to PHP Core HTTP. The reported results were:

| Case | Result |
| --- | --- |
| Actor token | 200; actor GUID `sid` preserved |
| Service-principal token | 200; `sid` absent |
| Privilege denial | 403 |
| Missing, expired, tampered, wrong-issuer, unknown-`kid`, malformed token | 401 |

This is user-confirmed evidence, not a Bridge-run result. It covers the global actor/service paths reported by the user; it does not cover tenant issuance or live Auth. The legacy PHP profile above is a separate issuer and mode.

## Release metadata, CI, and security behavior

- `composer.json` requires PHP `>=8.5 <8.6`; Composer's platform override is `8.5.0`. The lockfile records the same platform requirement. Strict validation passed.
- Composer has no explicit `version` field. The latest local version tag is `v0.12.2`; CI runs release jobs for `v*` tags. Older RC tags use the `v0.2.3-RC<n>` spelling. Select the next release/RC version deliberately; no tag was created or changed.
- `minimum-stability` is `stable` and `prefer-stable` is enabled. The lockfile changes observed in the pre-existing worktree update the platform/content hash; no dependency package version change was present in the reviewed diff.
- Bitbucket default, pull-request, and `v*` tag pipelines call `bin/ci/verify-php85.sh`. The version gate validates PHP 8.5, strict Composer metadata, lock installation/platform requirements, lint, PHPStan, and PHPUnit. A tag pipeline runs Packagist notification only after verification. The pipeline image is mutable `composer:2`; the script's PHP minor-version gate fails closed if that image no longer supplies PHP 8.5.
- Core's selected default JWT mode is `standard`; a consumer of archived PHP Identity must explicitly select `legacy`. A service chooses one configured profile; do not add permissive dual-profile fallback. Canonical .NET access tokens use the standard RS256 `winglet+jwt` profile with exact issuer and trusted key/JWKS configuration. The user-confirmed runs test each profile separately.
- CORS remains disabled by default in the current configuration. When enabled, the reviewed changes require explicit allowed origins and validate preflight origin, method, and headers. PHPUnit passed, including the checked-in CORS and HTTP kernel tests; external Base-image wire behavior was not freshly exercised in this Bridge run.
- Handoff context reports published `akikettu/wingletbase:v0.4.0-RC1` with PHP 8.5.12 and a prior local Core/Base HTTP smoke v1.1.2 PASS. This task did not independently pull or inspect that image, capture its digest, repeat the smoke, or run its security scan.

## Status and remaining acceptance

**Core RC status: READY FOR RC CANDIDATE.** The requested Core bounded checks pass, Composer metadata matches the PHP 8.5 target, and user-confirmed legacy and canonical Identity HTTP results are recorded without presenting them as Bridge-run tests.

**Not yet accepted for production or downstream Credits rollout:**

- Tenant actor issuance and Core HTTP authorization.
- Live Auth integration; the canonical acceptance used a test-only Auth client.
- Database-backed Identity login, renew, revoke/close, and the agreed expiry-bounded logout behavior.
- JWKS/key rotation and deployed JWKS availability/pinning.
- Downstream Credits route/privilege review and its acceptance suite.
- A fresh Base RC image digest/security-scan record and repeat of the latest Base/Core HTTP smoke on Docker-capable WSL.

Docker-backed checks were not available in the bounded Core catalog, and no separately approved Docker gate was configured for this task. The exact local WSL commands for a fresh final run are:

```bash
# From the Core repository root: runtime, Composer, lint, PHPStan, and PHPUnit gate.
./bin/ci/verify-php85.sh

# From the Core repository root: latest Core HTTP smoke in the published Base RC image.
bash scripts/http-integration-smoke.sh

# From the Core repository root: archived PHP Wing Identity legacy HTTP profile.
bash scripts/wing-identity-core-http-integration.sh

# From the winglet.identity checkout: .NET canonical issuer-to-Core HTTP profile.
bash tests/scripts/wingletnet-identity-core-http-integration.sh
```

Retain sanitized status-only results and image/runtime digests. Do not record bearer tokens or key material. The commands do not replace the remaining tenant, live Auth, lifecycle, rotation, or Credits checks.

## Existing worktree, files, and staging guidance

The repository already had extensive unstaged and untracked PHP 8.5, CORS, JWT, harness, and documentation changes before this report was written. They were preserved; no cleanup, staging, or unrelated source correction was made. This task updated the existing token acceptance note to record the newly confirmed .NET result and its limits, updated the interoperability note to point to that user-confirmed result, and added this final acceptance report.

Before a release commit, review the full `git status` and diff, then stage only the explicitly reviewed source, test, harness, and report paths intended for the RC. Do not use blanket staging such as `git add -A`; keep unrelated pre-existing work out of the release commit. This task did not run `git add`, commit, or change any release tag.

## Definition of done

- PHP 8.5 requirement/platform metadata and Composer strict validation: PASS.
- Core PHPUnit and PHPStan through the bounded Bridge catalog: PASS.
- Archived PHP Identity legacy HTTP and .NET canonical actor/service HTTP: USER-CONFIRMED PASS, explicitly distinguished from Bridge-run checks.
- Release readiness decision, evidence boundaries, remaining risks, exact WSL commands, and staging guidance: recorded here.
- Docker Base-image acceptance, tenant/live Auth/session lifecycle/key rotation, and Credits acceptance: follow-up required before production acceptance.
