# Winglet Core and Wing Identity migration note v1.1.0

**Date:** 2026-10-22  
**Core repository:** `wingletphp.core`, HEAD `06fd026` (working tree already contained unrelated local changes)  
**Identity repository:** `wing-identity`, HEAD `d821214` (read-only)  
**Decision:** Core can consume the current Identity session token through its frozen legacy profile when explicitly configured. The isolated HTTP harness now calls Identity's real `Session::getToken()` and sends those signed tokens through Core HTTP. This is not full live service acceptance: it does not start Identity's database-backed login or close endpoints.

## Evidence and versions

- Identity `composer.json` declares version `2024.1`.
- Identity `Dockerfile` uses `akikettu/wingng:2024.1-RC7` as its base image. This source value was inspected; the image, digest, runtime PHP version, and running service were not checked.
- Core `composer.json` requires PHP `>=8.5 <8.6`; its default JWT mode in `resources/config/40_auth.php` is `standard`.
- Identity's `Session::getToken()` signs with HS256 HMAC over the first two token segments. Header and JSON payload are produced with ordinary `base64_encode`, and the HMAC is appended as lowercase hexadecimal text. The header is `alg=HS256`, `typ=JWT`.
- Identity's `createTokenPayload()` emits `exp` as `Y-m-d H:i:s`, `jti` as session ID concatenated with `(1000 + version)` and `00`, numeric `sid`, `slv`, nullable `idi`, and `privileges: null`. It does not emit `iss`, `aud`, `kid`, roles, or groups.
- `Login` and `Sessions/CreateAndLogin` return this value as `sessionHash`. The token has a bounded session expiry but contains no cryptographic token profile marker distinguishing it from other JWT-like formats.

## Claim mapping and compatibility

| Identity output | Core legacy handling | Migration consequence |
| --- | --- | --- |
| `alg=HS256`, `typ=JWT` | Legacy parser verifies the configured shared-secret HMAC and does not use the standard profile's RS256 canonical rules. | Set `authentication.jwt.mode: legacy` for a service consuming this token; standard mode is the Core default. |
| Standard-base64 header and payload | Legacy parser decodes the payload using standard Base64. | Compatible with current Identity output. |
| Hex HMAC text in third segment | Legacy parser compares the exact hexadecimal HMAC. | Compatible with current Identity output; the standard JWT parser expects a Base64URL-decoded signature and is not the right profile. |
| `exp` date string | Legacy parser parses a date string and rejects expired values. | Compatible. Expiry is the only time-based validity check in Core. |
| `sid` | Maps to Core session ID. | Suitable for request/session association. |
| `slv` | Maps to Core session level. | Can satisfy Core minimum/maximum session-level route rules. |
| `idi` | Maps to Core identity/client ID and can be null for an unauthenticated Identity session. | Core's `authenticated` flag means a valid signed Identity session token; it does not guarantee a non-null identity. |
| `jti` | Maps to Core's legacy `identityVersion` field. | Identity currently composes this value from session ID and version; it is not a standalone version claim. Do not use it as a revocation/version oracle. |
| `privileges: null` | Normalizes to an empty privilege list. | No privilege grants are conveyed. `AuthContext::hasPrivilege()` returns false for named grants. |
| No `roles`, `groups`, `iss`, `aud`, or `kid` | Core leaves roles null and groups empty; issuer/audience/key ID policy cannot be applied. | Role-based Core routes deny unless an application supplies a separate trusted authorization translation. |

## Core configuration for a legacy consumer

Use the legacy mode only for the service that receives this Identity token. Inject the same Identity HMAC secret into the runtime secret provider and keep it out of source, images, fixtures, and reports. Do not enable both profiles as a permissive fallback: Core selects one token service at a time.

```php
return [
    'authentication' => [
        'jwt' => [
            'enabled' => true,
            'mode' => 'legacy',
            'legacy' => [
                'secret_config_key' => 'environment.Secret',
            ],
        ],
    ],
];
```

For a future Identity migration to Core's canonical profile, the issuer must issue `typ=winglet+jwt`, RS256 signatures, `kid`, an exact configured `iss`, numeric `iat`/`exp`, `sid`, dedicated `session_version`, `token_context`, and a string-array `privileges`. User/service assurance and tenant claims must follow the canonical contract. A translation from Identity roles to privilege names must be owned by a trusted service and documented; Core does not infer privilege grants from role names.

## Authorization and session lifecycle

- Core enforces session-level and group/role route declarations through `AccessDecisionManager`. The Identity payload can currently support session-level checks, but not role/group checks.
- Core exposes `AuthContext::hasPrivilege()` and `PrivilegeMatcher`, but route metadata does not apply privileges by itself. Applications need an explicit checker for privilege-gated routes.
- Identity declares role and item requirements in controller access requirement configuration, but those requirements are not emitted in `Session::createTokenPayload()`.
- Identity persists session state and expiry. Its close endpoint sets state `901`; the session check endpoint reloads and extends a session. Core's legacy parser performs no Identity database lookup and therefore cannot notice closure or state changes before the token's `exp` time.
- No standalone session-version claim is emitted. The current `jti` composition must not be treated as proof of revocation or fresh authorization state.

## Verification and acceptance

The unit fixture remains a sanitized, hand-built encoding test. In addition, `scripts/wing-identity-core-http-integration.sh` builds the read-only Identity Dockerfile, invokes the actual `Session::getToken()` implementation on test-only session objects, and sends those tokens to a Core HTTP container in explicit legacy mode. It uses a random per-run test key in a mode-0600 temporary file, removes its containers/image and temporary files, and never reads a production secret. Expected statuses are documented in the companion compatibility record. Docker was unavailable to this Bridge run, so this integration harness was not executed here. Database-backed login and session close remain outside its scope.

The source-authentic token-generation-to-Core-HTTP path is implemented but remains runtime-unverified until `bash scripts/wing-identity-core-http-integration.sh` passes on a Docker-capable WSL host. The integration covers valid, missing, invalid-signature, expired, minimum-level allow/deny, role denial, and closed-state token behavior. Full release acceptance still requires DB-backed Identity login/close and revocation checks, logging review, and the downstream Credits suite. See the companion compatibility record for exact criteria and the Credits checklist.

## Identity change proposal if roles or revocation are required

Keep this repository read-only for this migration. If downstream services require role/privilege authorization or prompt logout/revocation, propose an Identity token contract change that emits a dedicated stable session version and explicit role/privilege claims from trusted data, identifies the issuer and audience, and supports key rotation with `kid`. Prefer a standards-conforming canonical RS256 token profile. Core can then validate the signed claims, but live revocation still requires a defined introspection/session lookup boundary or deliberately short token lifetime; it cannot be inferred from the current token alone.
