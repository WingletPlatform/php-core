# Winglet.NET Identity to PHP Core JWT interoperability v1.0.0

**Date:** 2026-10-22  
**Write repository:** `wingletphp.core`  
**Read-only issuer:** `winglet.identity`  
**Identity revision:** `0f745f01fabdb47dda5dde65ce24aa5d7bed4724`  
**Decision:** Core can consume the current canonical Identity actor and service-principal JWTs after preserving opaque session identifiers and allowing sessionless service tokens. This source-level correction is covered by synthetic signed PHP fixtures. A later user-confirmed WSL run exercised both .NET production issue paths and Core HTTP; see [Identity-Core-Token-Acceptance-2026-10-22.md](Identity-Core-Token-Acceptance-2026-10-22.md) for its scope and limits. That run was not performed by this Bridge task.

## Producer contract observed in source

The inspected issuer is the current `winglet.identity` .NET service, not the separately archived PHP Wing Identity implementation documented in `PHP-Identity-Compatibility-Acceptance-2026-10-22.md`.

- `IdentityService.IssueAsync` issues user access tokens as `typ=winglet+jwt`, signed RS256 by `TokenSigner` with `Identity:CurrentKeyId` as `kid`. The configured issuer defaults to `https://identity.winglet.local` and access-token lifetime defaults to five minutes.
- User claims include `sub` (decimal identity ID string), `sid` (GUID session ID), integer `session_version`, `token_context` (`global` or `tenant`), `acr=urn:winglet:acr:authenticated`, numeric `auth_time`, integer `iat`/`exp`, and the effective `privileges` array. Tenant tokens add positive `tenant_id`. User tokens do not include `aud` or `identity_type`.
- `ServicePrincipalService.IssueAsync` issues the same `typ`/RS256/issuer/kid profile with `sub`, `client_id`, `identity_type=service`, `subject_kind=caller`, `session_version=1`, `token_context`, numeric `iat`/`exp`, optional tenant ID, and effective privileges. Service-principal tokens do not have `sid`, `acr`, or `auth_time`.
- `TokenSigner.PublicKey` publishes one RSA verification key at `/.well-known/jwks.json`. The Identity OpenID configuration document advertises that JWKS URL. Core's current `JwksProvider` requires a configured URL; it does not discover that URL automatically. Configure the exact issuer and an allowlisted/pinned JWKS endpoint or supply a trusted public key directly.
- The user/service token audiences are omitted. Identity's separate target-bound `service_authentication` tokens do include an audience; those are not the actor/service-principal access tokens described above.
- User privileges are loaded from Auth authorization snapshots. `legacy.role.*` and `legacy.group.*` reserved privileges are filtered out of canonical `privileges`; no canonical `roles` or `groups` claim is emitted. Core must authorize by explicit privileges and must not infer grants from roles.

## Session lifecycle and revocation

Identity persists sessions. Renewal checks active status, expiration and the renewal verifier; revoke marks the session revoked and increments `SessionVersion`. Core validates a signed access token locally and does not query Identity or compare the embedded `session_version` with the database. A token already issued before renewal/revocation remains accepted by Core until its `exp` (five minutes by default), subject to local signing-key validity. This is expiry-bounded logout, not immediate revocation.

The inspected `TokenSigner` exposes one current signing key. `kid` and JWKS provide key identity/distribution, but the source shown here does not expose an overlapping key set or a key-rotation protocol. Production rotation behavior needs a separate deployment acceptance check.

## Core compatibility change

Core previously required every canonical `sid` to be a positive decimal string. That rejected Identity's GUID actor session IDs. It also required `sid` on service-principal tokens, where Identity emits none. The canonical parser now accepts a bounded non-empty opaque string `sid`, keeps the existing `getSessionId(): ?int` behavior for numeric IDs, and exposes the exact string through `getSessionSubject()`. A missing `sid` is accepted only for `identity_type=service`; actor tokens still require it. No integer surrogate is derived from a GUID.

Configuration remains one profile per service. Use `authentication.jwt.mode=standard`, set `authentication.jwt.standard.issuer` to the exact Identity issuer, and configure a trusted RS256 key or static JWKS URL with URL policy. The Core-wide default currently allows HS256 for the old standard profile, but canonical `winglet+jwt` selects only RS256 validation. Do not add a permissive dual-mode fallback. The distinct archived PHP Identity token remains a separate legacy profile and must be configured explicitly as `legacy` by its consumers.

## Repeatable coverage and evidence limits

`tests/Security/Auth/Jwt/WingletNetIdentityCanonicalFixtureTest.php` signs source-shaped actor and service-principal claim sets with an ephemeral PHP-generated RSA key. It proves Core parsing/projection, GUID session preservation, sessionless service-token acceptance, privilege access, and invalid-signature rejection. It is a synthetic fixture, not a .NET-issued token or a test of Identity's production `IssueAsync` methods.

No DB-free invocation of Identity's production actor issuer was found: user issuance is inside `IdentityService.IssueAsync`, which obtains an authorization snapshot through Auth and uses the EF-backed session. Service-principal issuance likewise verifies the principal and resolves an Auth snapshot through its database-backed service. This task did not modify the read-only Identity repository to add an issuer seam or test hook. The PHP fixture therefore does not prove cross-runtime serialization by the .NET JWT library.

### Production-issuer-to-Core HTTP harness

`winglet.identity/tests/scripts/wingletnet-identity-core-http-integration.sh` now starts a disposable MariaDB through Testcontainers and calls both production `IdentityService.IssueAsync` and `ServicePrincipalService.IssueAsync`. A test-only Auth client supplies deterministic global snapshots; a separate actor snapshot has no `credits.read` privilege for the 403 case. The runner exports actual actor and service-principal tokens plus the matching public PEM and JWKS to a mode-0700 temporary directory. Its test-only private key and token file are mode 0600; the container receives only the public key. Testcases also include a validly signed expired token, validly signed wrong-issuer and unknown-`kid` variants, and a signature-tampered production token. No production endpoint or key export was added.

The shell harness configures exactly one Core profile (`standard` canonical RS256, pinned issuer, one test key, no legacy fallback), starts Core's HTTP kernel in an isolated container, and verifies actor/service allow, privilege denial, and missing/expired/tampered/wrong-issuer/unknown-key/malformed rejection. Actor `sid` is checked as a GUID string and the service response confirms no `sid`. Run locally from the Identity checkout with `bash tests/scripts/wingletnet-identity-core-http-integration.sh`; it requires Docker/Testcontainers, `dotnet`, `php`, and `curl`. For this implementation, the bounded .NET `verify.sh` gate passed (Release build, formatting, conformance and all 24 tests), the .NET smoke gate passed, Core PHPUnit passed (166 tests, 661 assertions), and Core PHPStan reported no errors. Those checks validate the projects and compile the issuer runner; they do not themselves execute this cross-repository HTTP harness. The user later confirmed that the harness passed on WSL, using disposable MariaDB and a test-only Auth client; this Bridge task did not run it. Tenant issuance, live Auth, database-backed login/renew/revoke, and JWKS rotation remain open before downstream release acceptance.

The user confirmed a successful WSL run of the PHP Wing Identity → Core legacy HTTP v1.2.0 harness with this observed output: “PASS: Identity Session::getToken() -> Core legacy HTTP integration completed. Observed: valid 200; missing/invalid signature/expired 401; low level/role 403; closed-state token 200 until exp.” This is separate evidence for the archived PHP issuer in legacy mode. It does not verify the .NET canonical issuer and does not prove database-backed PHP Identity login. Do not treat the test fixture or harness implementation as a runtime PASS for .NET.

## Acceptance remaining

1. Retain the user-confirmed WSL result for the production-issuer-to-Core HTTP harness as external evidence; do not label it Bridge-run. Re-run if a fresh sanitized artifact, image digest, or runtime identity is needed.
2. The user-confirmed global actor/service matrix observed actor/service 200, privilege denial 403, and missing/expired/tampered/wrong-issuer/unknown-`kid`/malformed 401. Tenant issuance and live Auth remain outside that result.
3. The user-confirmed archived PHP Wing Identity → Core legacy v1.2.0 run is recorded in [Identity-Core-Token-Acceptance-2026-10-22.md](Identity-Core-Token-Acceptance-2026-10-22.md). Re-run it on Docker-capable WSL when image digests/runtime versions are needed; it does not prove .NET canonical interoperability.
4. Verify Identity DB-backed login, renewal, close/revoke, and accepted logout latency. Confirm key rollover behavior and the JWKS deployment URL/pinning policy.
5. Complete the downstream Credits checklist in `PHP-Identity-Compatibility-Acceptance-2026-10-22.md` against the actual selected issuer/profile; validate the service's route privileges.

## Credits checklist additions

- Select exactly one Identity issuer and matching Core parser mode. Do not mix the archived PHP HS256 legacy token with the .NET RS256 canonical token.
- Configure the exact issuer and trusted RS256 key/JWKS source in Core; record the issuer, key ID and image/runtime digest, never key material or bearer tokens.
- Exercise actor global and tenant tokens, service-principal tokens without `sid`, malformed/missing/expired/bad-signature tokens, and privilege allow/deny routes.
- First execute `bash tests/scripts/wingletnet-identity-core-http-integration.sh` from the Identity checkout and attach the sanitized status-only result; it validates actor/service global issuance and Core HTTP handling, not tenant issuance.
- Record that GUID `sid` is available through `AuthContext::getSessionSubject()` while the compatibility numeric `getSessionId()` returns null for it.
- Agree the five-minute default token window and verify renewal/revoke behavior with the Identity database before relying on logout semantics.

