# Wing Identity and Core compatibility acceptance v1.2.0

**Date:** 2026-10-22  
**Identity source:** `wing-identity` revision `d821214`, Composer version `2024.1` (read-only)  
**Identity image base in Dockerfile:** `akikettu/wingng:2024.1-RC7` (source-confirmed; digest/runtime not checked)  
**Core source:** `wingletphp.core` revision `06fd026`; the working tree had pre-existing local edits before this task.  
**Status:** Source-authentic Identity token generation and a reproducible Core HTTP integration harness are implemented. The user confirmed a successful v1.2.0 WSL HTTP run; image/runtime digests and DB-backed Identity lifecycle acceptance remain pending.

## Executive result

Current Wing Identity does **not** issue Core's canonical `winglet+jwt` profile. It issues its historical JWT-like HS256 session token. Core already has a matching frozen `LegacyJwtTokenService`, and no production code correction was necessary. A Core service must explicitly select the legacy mode; Core's default is standard JWT mode.

The current token carries session identity and level, but does not convey roles or privilege grants. A valid token can satisfy a Core session-level route requirement; the same token cannot satisfy a Core role/group requirement or `hasPrivilege()` check. Identity's own role/item requirements are server-side controller configuration and do not appear in the emitted token. Core cannot observe a closed or otherwise changed Identity session until token expiry because the legacy parser does not query Identity's session store.

## Source evidence

Inspected current source in `wing-identity` at revision `d821214`:

- `composer.json` declares `2024.1`; the Dockerfile names `akikettu/wingng:2024.1-RC7` as its base.
- `Applications/Identity/Entities/Sessions/Session.php::getToken()` builds header and payload using standard `base64_encode`, signs `header.payload` with `hash_hmac('sha256', ..., secret)` (hex output), and appends the hexadecimal HMAC as the third segment.
- `createTokenPayload()` produces date-string `exp`, composite `jti` (`session id` + `(1000 + version)` + `00`), `sid`, `slv`, nullable `idi`, and `privileges: null`. It emits no issuer, audience, key ID, role, or group claim.
- `Controllers/Authentication/Login.php` and `Controllers/Sessions/CreateAndLogin.php` return the token as `sessionHash`.
- `Controllers/MyIdentity/Security/setPassword.php` defines access requirements from configured minimum level, item types, items, and roles. These are not copied into the session token.
- Session state and expiration are persisted. `Controllers/Sessions/Close.php` closes a session by setting state `901`; `Controllers/Sessions/Check.php` reloads and extends it. Core has no connection to those records.

The Core legacy parser in `src/Security/Auth/Jwt/LegacyJwtTokenService.php` expects this exact historical shape: standard Base64 payload, hex HMAC text, string date expiry, and legacy `idi`/`sid`/`jti`/`slv` claims. It normalizes null privileges to an empty list and leaves roles absent. Canonical `StandardJwtTokenService` intentionally requires `typ=winglet+jwt`, RS256, `kid`, issuer, numeric time claims, dedicated session version and privilege claims; it is not compatible with the current Identity token.

The existing `tests/Security/Auth/Jwt/CanonicalJwtConsumerFixtureTest.php` is a synthetic Core canonical-profile fixture and does not model Wing Identity. `WingIdentityLegacyTokenFixtureTest` remains a fast sanitized parser fixture. The new `scripts/wing-identity-core-http-integration.sh` builds from the Identity Dockerfile in read-only source, populates test-only Session objects by reflection, calls the real `Session::getToken()`, and consumes those outputs through Core's actual Apache/HTTP pipeline with `mode=legacy`. It is source-authentic issuance, not a DB-backed Identity login.

## Claim and authorization results

| Behavior | Result |
| --- | --- |
| Actual `Session::getToken()` output parses through Core HTTP | Harness implemented; Docker runtime execution remains pending. |
| Identity ID/session ID/session level projection | Covered: `idi` → identity ID, `sid` → session ID, `slv` → session level. |
| `jti` meaning | Projected by legacy Core as `identityVersion`; Identity composes it from session ID and version, so it is not an independent version/revocation claim. |
| Privileges | Identity emits null; Core exposes no grants (`[]`). No role-to-privilege inference occurs. |
| Roles/groups | Identity emits neither; Core has `roles=null`, `groups=[]`. A group-protected Core route is denied. |
| Session-level authorization | A level 100 fixture is allowed by a route requiring minimum level 100. |
| Invalid signature / expired token | Unit fixture and HTTP harness cases implemented; HTTP execution remains pending. |
| Issuer/audience/key rotation | Not represented in current Identity token; cannot be enforced by this profile. |
| Close/revocation before expiry | Not detected by Core's stateless token validation. |
| Real HTTP, Docker, DB session checks | Harness now covers real Core HTTP; not run in this Bridge session. Identity DB login/close remains unimplemented and unverified. |

## Core configuration and security boundary

For an application consuming this Identity profile, explicitly select `authentication.jwt.mode = legacy` and resolve `authentication.jwt.legacy.secret_config_key` (default `environment.Secret`) from a runtime secret provider. Do not place the shared HMAC secret in source control, an image, tests using a real value, or task documents. Do not silently accept both token modes as a fallback. Use a distinct secret per issuer/consumer boundary and rotate through an agreed deployment plan.

The token is not a conforming canonical JWT: its signature segment is raw hex text, and the payload uses standard Base64. Current Core legacy mode handles the exact format. New services should prefer a standards-conforming canonical RS256 profile with issuer, audience, key ID, dedicated session version, and explicit privilege grants.

## Changes in Core

- Added `scripts/wing-identity-core-http-integration.sh`. It requires a clean Identity checkout at pinned revision `d821214facf8fd4856e234261ac309a0f390c711`, builds its Dockerfile, generates a random temporary test key, calls actual `Session::getToken()`, starts a Core HTTP container, and asserts valid/missing/tampered/expired, level allow/deny, role denial, and closed-state statuses.
- The script asserts that state 901 and state 100 sessions with identical claims produce the same token, then shows Core accepts that token until expiry. This confirms stateless expiry-bounded behavior; it does not call the DB-backed close controller.
- `tests/Security/Auth/Jwt/WingIdentityLegacyTokenFixtureTest.php` remains the isolated parser/authorization unit fixture.
- No production PHP code was changed. Existing local edits in the Core checkout were preserved.

## Verification record

The user confirmed a successful Docker-capable WSL run of the v1.2.0 HTTP harness. Observed output: “PASS: Identity Session::getToken() -> Core legacy HTTP integration completed. Observed: valid 200; missing/invalid signature/expired 401; low level/role 403; closed-state token 200 until exp.” This is user-confirmed evidence, not a run performed by this Bridge task. The harness uses the archived PHP Identity legacy token, not the .NET canonical issuer, and does not perform database-backed login or close. Current Bridge checks are recorded in [Identity-Core-Token-Acceptance-2026-10-22.md](Identity-Core-Token-Acceptance-2026-10-22.md).

| Check | Result |
| --- | --- |
| Identity source inspection | Complete; revision and version recorded above |
| Core parser/profile comparison | Complete by source inspection |
| Identity Session::getToken() to Core real HTTP cases | User-confirmed WSL v1.2.0 PASS; see observed result above and re-run with `bash scripts/wing-identity-core-http-integration.sh` when image/runtime details are needed |
| Core production correction | None needed for parsing current token in explicit legacy mode |
| Core normal tests | Current Bridge run: PHPUnit 166 tests/661 assertions, PHPStan clean, Composer validation passed |
| Source-authentic archived PHP token signed by real Identity method / Core HTTP route | User-confirmed v1.2.0 WSL PASS; this is the archived PHP producer only |
| .NET canonical token from production `IssueAsync` / Core HTTP route | Pending; not covered by the archived PHP run |
| Session close/revocation and database lifecycle | Pending |
| Docker/Base runtime and digest | Pending |

## Acceptance criteria still open

1. Re-run Core PHPUnit/PHPStan in the supported PHP 8.5 environment when a fresh verification record is needed; current Bridge results are in the linked token acceptance report.
2. Run the actual Identity and Core images together; record exact image digests, PHP versions, Core/Identity revisions, and tested configuration without recording secrets or live bearer tokens.
3. Run `bash scripts/wing-identity-core-http-integration.sh` on Docker-capable WSL. It exercises missing, valid, malformed, expired, tampered, test-secret-mismatch, and protected route cases; record status codes and verify logs contain no bearer token or secret.
4. Verify Identity session close, state changes, expiry, and any required revocation semantics against its database. Establish whether Core consumers need synchronous introspection or accept expiry-bounded logout.
5. Define an owned role-to-privilege mapping if downstream Core routes require privileges. Verify allow and deny cases, including role-only and privilege-bearing principals.
6. Verify issuer/audience/key rotation policy or document why legacy shared-secret scope is acceptable for each consumer.

## Identity follow-up proposal (read-only repository)

If services require roles, privileges, or prompt revocation, propose an Identity change rather than inferring grants in Core: issue a versioned, standards-conforming token with issuer/audience/key ID, a dedicated session version, explicit role/privilege claims derived from trusted server-side policy, and a clear close/revocation contract. For Core canonical mode, use its documented RS256 `winglet+jwt` profile. No change was made to the read-only Identity repository in this task.

## Downstream Credits checklist

1. Record the Credits revision, selected Identity revision, and actual token profile; confirm that the deployed Core mode matches it.
2. Configure secret injection/rotation without committing or baking the HMAC secret into source or image.
3. Inventory Credits routes and current roles. Decide whether session level alone is sufficient; otherwise approve a trusted role-to-privilege mapping or the Identity token-contract update.
4. Exercise public, minimum-level, role/group, and privilege routes with allowed and denied principals, including missing, expired, malformed, and tampered tokens.
5. Test session extension, close, expiry, and revocation behavior against Identity's database and record the accepted logout latency.
6. Review request/security logs for bearer-token leakage and confirm that failed authentication returns 401 while insufficient authorization returns 403.
7. Run the Credits, Identity, and Core acceptance suites on the same runtime/image digests; record the results and unresolved risks before deployment.

## Definition of done

Core source-level compatibility is demonstrated by current source inspection and a focused fixture/test design. Full Wing Identity and Core acceptance is **not complete** until the tests execute and real HTTP/session lifecycle checks pass. No Identity repository change, commit, push, deployment, or Docker run was made.
