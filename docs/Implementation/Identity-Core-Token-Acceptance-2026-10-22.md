# Winglet Identity to Core token acceptance status

**Date:** 2026-10-22  
**Write scope:** `wingletphp.core`, `winglet.identity`  
**Core HTTP harness:** `scripts/wing-identity-core-http-integration.sh` v1.2.0  
**.NET Identity revision inspected:** `0f745f01fabdb47dda5dde65ce24aa5d7bed4724`

## Result

Core's canonical parser changes for opaque GUID session IDs and service principals without `sid` are present in the current worktree and have source-shaped PHP fixtures. The user confirmed a separate successful WSL run that exercised the actual .NET production issue methods and sent their returned actor and service-principal tokens through Core HTTP. This is user-confirmed evidence, not a Bridge-run result. The covered global actor/service paths passed; tenant issuance and several live lifecycle/key-rotation checks remain open.

Separate, user-confirmed evidence is recorded for the archived PHP Identity legacy HTTP run. It is not evidence for the .NET RS256 profile and does not test PHP Identity database login.

## Producer contract and user-confirmed .NET acceptance

The current .NET producer uses `TokenSigner` to issue RS256 (`alg=RS256`) tokens with `typ=winglet+jwt`, a `kid`, and the configured issuer (default `https://identity.winglet.local`). Access tokens omit `aud`.

| Token | Expected claims |
| --- | --- |
| Actor | `sub` as decimal identity ID; `sid` as GUID string; integer `session_version`; `token_context`; `acr=urn:winglet:acr:authenticated`; integer `auth_time`, `iat`, `exp`; `privileges` array. Tenant tokens additionally have positive `tenant_id`. |
| Service principal | `sub`, `client_id`, `identity_type=service`, `subject_kind=caller`, `session_version=1`, `token_context`, integer `iat`/`exp`, `privileges` array. Tenant tokens additionally have positive `tenant_id`. These tokens omit `sid`, `acr`, and `auth_time`. |

The public verification key is exposed by `TokenSigner.PublicKey` and the Identity JWKS endpoint. Never copy a private key, production key, or bearer token into this report. The Core fixture `tests/Security/Auth/Jwt/WingletNetIdentityCanonicalFixtureTest.php` creates a PHP/OpenSSL RSA key and source-shaped claims; it remains synthetic and does not itself test .NET serialization or production issuance.

The user confirmed that the WSL run used a disposable MariaDB and a test-only Auth client, called both `IdentityService.IssueAsync` and `ServicePrincipalService.IssueAsync`, then passed the resulting tokens through the Core HTTP consumer. The observed acceptance was: actor 200 with its GUID `sid` preserved; service 200 with `sid` absent; privilege denial 403; and missing, expired, tampered, wrong-issuer, unknown-`kid`, and malformed tokens 401. This result is **USER-CONFIRMED**, not a Bridge-run verification. Tenant issuance and live Auth integration were not covered.

No production Identity files were changed for this Core task. The confirmed run used a disposable test database and test-only Auth client; it does not establish behavior against live Auth or production data. Keep generated artifacts in a private temporary directory and never log token contents or private key material.

## Core HTTP acceptance matrix for the .NET token fixture

The confirmed fixture called the actual actor and service issue methods and sent those returned access tokens through Core's HTTP pipeline. User-confirmed observations:

| Case | Expected |
| --- | ---: |
| Valid actor with GUID `sid` and allowed privilege | 200 (USER-CONFIRMED) |
| Valid service principal without `sid` and allowed privilege | 200 (USER-CONFIRMED) |
| Missing, malformed, expired token; wrong issuer; unknown key ID | 401 (USER-CONFIRMED) |
| Valid token lacking route privilege | 403 (USER-CONFIRMED) |
| Tampered token | 401 (USER-CONFIRMED) |

Check that the actor GUID is available as `AuthContext::getSessionSubject()` and that `getSessionId()` remains `null` for the GUID. Check that the service token has no session subject. Exercise both global and tenant claims where relevant. Identity's default access-token lifetime is five minutes; Core validation is stateless, so a renewed or revoked session does not invalidate an already issued token before expiry.

## Archived PHP Identity legacy HTTP evidence (separate profile)

The following result was supplied by the user as an observed successful WSL run of the checked-in v1.2.0 harness. It is transcribed as user-confirmed evidence, not as a test executed by this Bridge run:

> PASS: Identity Session::getToken() -> Core legacy HTTP integration completed. Observed: valid 200; missing/invalid signature/expired 401; low level/role 403; closed-state token 200 until exp.

This harness uses the archived PHP Identity `Session::getToken()` HS256-like legacy format and Core `mode=legacy`. It does not use `winglet.identity` .NET, does not prove canonical RS256 interoperability, and does not log into or close a session through the Identity database.

To reproduce that legacy WSL run from the Core repository root, use this one command on a Docker-capable WSL host with the pinned archived PHP Identity checkout available at the script's documented default location:

```bash
bash scripts/wing-identity-core-http-integration.sh
```

If the checkout is elsewhere, provide it explicitly:

```bash
WING_IDENTITY_SOURCE=/absolute/path/to/archived/wing-identity bash scripts/wing-identity-core-http-integration.sh
```

The script pins archived Identity revision `d821214facf8fd4856e234261ac309a0f390c711`, uses a random test-only secret, and cleans up its named temporary container, image, and work directory. This is a reproducible legacy check only; it is separate from the .NET canonical profile.

## Credits follow-up checklist

1. Select the intended Identity issuer and matching Core profile; do not mix archived PHP HS256 legacy tokens with .NET RS256 canonical tokens.
2. Configure the exact .NET issuer and an allowlisted/pinned JWKS URL or trusted RS256 public key. Record issuer, key ID, revisions, and runtime/image digests only; never record secret key material or bearer tokens.
3. Run tenant actor issuance through the actual production method and Core HTTP; tenant issuance was not covered by the user-confirmed global actor/service result.
4. Reconfirm any not-yet-observed cases, including invalid signature and actor-missing-`sid` / service-missing-required-claim behavior, through Core HTTP; require 401 for authentication failures and 403 for authorization failures.
5. Verify that a service token without `sid` remains sessionless and the actor's GUID session identifier is preserved exactly.
6. Verify database-backed login, renewal, and revoke/close behavior against live Auth/Identity integration, and agree on the accepted expiry-bounded logout window.
7. Confirm key rollover behavior and that deployment publishes the configured JWKS endpoint; the inspected current signer exposes its current key, not an overlap/rotation protocol.

## Verification and limitations

- Core bounded checks passed: `composer phpunit` (166 tests, 661 assertions), `composer phpstan` (no errors), and `composer validate --strict`.
- Identity `./scripts/verify.sh` passed: restore, Release build (zero warnings/errors), OpenAPI freshness, format verification, 24 tests (zero failures/skips), and packaging/conformance.
- Identity's `./scripts/smoke.sh` passed against its local loopback readiness boundary. This is a service health smoke check, not a substitute for the separate .NET token acceptance.
- User-confirmed WSL integration exercised .NET-produced actor/service tokens through Core HTTP using disposable MariaDB and a test-only Auth client. No Bridge-run .NET-to-Core HTTP result, tenant result, live Auth result, DB-backed login/renew/revoke result, production key rotation result, or production deployment result is claimed.
- Existing local worktree changes were present before this task and were left intact.

## Completion record

This document records both user-confirmed integration outcomes while keeping the archived PHP legacy profile separate from .NET canonical RS256. Core RC readiness may proceed on the evidence recorded here and in the final Core acceptance report; tenant, live Auth, database-backed login/renew/revoke, JWKS rotation, and downstream Credits checks remain release follow-ups.
