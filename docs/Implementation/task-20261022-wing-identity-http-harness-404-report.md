# Wing Identity HTTP harness 404 fix report

**Task:** `task-20261022T191752Z-cd9977ec0687`  
**Write repository:** `wingletphp.core`  
**Date:** 2026-10-22

## Summary

Fixed the Core integration fixture's Apache 404. It mounted the app's `public/` directory as Apache's document root but omitted the front-controller rewrite present in the working HTTP smoke fixture. Apache therefore returned its own 404 for `/identity/level` before Core routing and authentication ran.

## Changes

- Added `public/.htaccess` generation to `scripts/wing-identity-core-http-integration.sh`, matching the rewrite in `scripts/http-integration-smoke.sh` v1.1.2.
- Versioned the harness as 1.0.1. HTTP assertions now retain response headers and bodies; failures include those details, recent container logs, and available Apache access/error logs.
- Routed the role-denial assertion through the same diagnostics and kept the expected authentication semantics: unauthenticated/invalid requests return 401; insufficient level or role returns 403.
- Updated the companion implementation notes with the root cause and exact rerun command.

## Files

- `scripts/wing-identity-core-http-integration.sh`
- `docs/Implementation/Wing-Identity-Core-HTTP-Integration-2026-10-22.md`
- `docs/Implementation/task-20261022-wing-identity-http-harness-404-report.md`

## Verification

- `bash -n scripts/wing-identity-core-http-integration.sh` and `git diff --check` on the task files: passed.
- Bounded `composer phpunit`: passed (163 tests, 639 assertions).
- Bounded `composer validate --strict`: passed.
- Docker integration: not run in this Bridge environment; Docker runtime behavior remains unverified here.

## Deviations and risks

No production Core code, Identity source, or Base repository was changed. The script still depends on Docker-capable local setup and a clean Identity checkout at the pinned revision. The exact local rerun command is `bash scripts/wing-identity-core-http-integration.sh` from the Core repository root.
