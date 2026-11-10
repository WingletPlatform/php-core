# Task completion report: Wing Identity and Core compatibility

**Bridge task:** `task-20261022T111443Z-ce0b7f3d490d`  
**Date:** 2026-10-22  
**Repositories inspected:** Core `wingletphp.core` and read-only Identity `wing-identity`  
**Repositories modified:** Core `wingletphp.core` only

## Summary

Inspected the actual Wing Identity source at revision `d821214` (Composer version `2024.1`) and Core at revision `06fd026`. Identity emits the historical HS256 JWT-like session token, not Core's canonical RS256 `winglet+jwt` profile. Core's existing legacy parser already matches Identity's Base64, hexadecimal HMAC, date-string expiry, and legacy claim shape when `authentication.jwt.mode` is explicitly set to `legacy`. No production code correction was necessary.

Identity's token does not include roles or privilege grants: `privileges` is null, and roles/groups are absent. Core maps `sid`, `slv`, `idi`, and `jti`; the latter is a composite session ID/version string and must not be treated as a dedicated revocation version. Core can apply session-level route rules but cannot infer roles or privileges, nor detect Identity session close/state changes before token expiry.

## Changes

- Added a sanitized Identity fixture test with positive claim projection, session-level authorization, role-route denial, expired-token rejection, and tampered-signature rejection.
- Added a detailed versioned Core/Identity migration note.
- Updated the versioned Identity compatibility acceptance record with source evidence, versions, authorization mapping, risks, open acceptance criteria, and the Credits checklist.
- No production PHP code changed. The Identity repository was not modified.

## Files

- `tests/Security/Auth/Jwt/WingIdentityLegacyTokenFixtureTest.php`
- `docs/Implementation/Winglet-Core-Wing-Identity-Migration-2026-10-22.md`
- `docs/Implementation/PHP-Identity-Compatibility-Acceptance-2026-10-22.md`
- `docs/Implementation/task-20261022-wing-identity-core-compatibility-report.md`

## Verification

The bounded normal-test catalog was queried for `wingletphp.core` and returned no entries. No arbitrary host command, PHPUnit, PHPStan, Docker, or live Identity HTTP check was run. The new test is therefore unexecuted. No live signed token or database-backed session lifecycle was available. The inspected Identity Dockerfile names base image `akikettu/wingng:2024.1-RC7`; image digest and runtime versions were not verified.

## Deviations and risks

- Source-generated fixture coverage stands in for live token capture; its claims and signature format mirror the inspected Identity implementation, but it is not a live signed token.
- Identity session close/revocation is not reflected in a Core stateless token until `exp`; acceptance requires a joint service test and a decision on revocation latency.
- Core defaults to standard mode, so downstream applications must select legacy mode for this current Identity token. Role/privilege routes require a separate trusted mapping or an Identity token-contract change.
- The Core worktree contained many pre-existing local changes before this task. Those files were preserved; this task only added the fixture and migration note and refreshed the Identity acceptance record.

## Definition of done

Source-level compatibility findings, focused test source, migration guidance, risks, and follow-up acceptance criteria are recorded. Full runtime acceptance remains pending until tests execute and real Identity/Core HTTP and session lifecycle checks pass. No commit, push, deploy, or Docker run was performed.
