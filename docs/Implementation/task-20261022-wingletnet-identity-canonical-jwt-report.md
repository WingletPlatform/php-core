# Winglet.NET Identity canonical JWT acceptance report

**Date:** 2026-10-22  
**Repository:** `wingletphp.core`  
**Identity read-only revision:** `0f745f01fabdb47dda5dde65ce24aa5d7bed4724`  
**Core base revision:** `06fd026cbf3da7bb00f575ef09dc4af5c5190fcc`

## Summary

Compared Core's canonical JWT consumer with the registered Winglet.NET Identity source. The source emits canonical RS256 tokens, and the comparison found that Core rejected Identity's GUID `sid` actor claims and sessionless service-principal tokens. Core now preserves opaque canonical session subjects and accepts an omitted `sid` only for service identities. Signed source-shaped tests cover both token kinds and invalid signatures.

The source comparison and synthetic PHP consumer tests do not establish that the .NET issuer produced a token or that the live HTTP path passed. The checked-in WSL v1.2.0 legacy harness evidence says that Docker execution is pending, and that harness tests the separate archived PHP Identity token profile. No verified local WSL PASS output was found, so none is recorded.

## Changes

- `src/Security/Auth/Jwt/StandardJwtTokenService.php`: accept non-empty bounded string session subjects for canonical actor tokens, preserve numeric `getSessionId()` projection where applicable, and permit absent `sid` only for `identity_type=service` tokens.
- `src/Auth/AuthContext.php`: return the original string `sid` from `getSessionSubject()` when present; retain the previous numeric fallback.
- `tests/Security/Auth/Jwt/WingletNetIdentityCanonicalFixtureTest.php`: signed synthetic fixtures for canonical actor and service-principal claims, privilege projection, opaque GUID session IDs, sessionless service IDs, and invalid signatures. The test class explicitly identifies its tokens as synthetic.
- `docs/Implementation/WingletNet-Identity-Core-JWT-Interoperability-2026-10-22.md`: versioned issuer profile, key distribution, authorization, session lifecycle, configuration, and remaining acceptance record.

No Identity repository was modified. No commit, push or deployment was performed.

## Verification

Bounded Core normal tests, repository `wingletphp.core`:

- `composer phpunit`: PASS, PHP 8.5.9, 166 tests, 661 assertions.
- `composer phpstan`: PASS, no errors.
- `composer validate --strict`: PASS.

The Core test suite includes the new synthetic source-shaped fixture. No test in this run called the .NET production token issuers. The bounded catalog did not contain a Docker/Wsl acceptance test; no arbitrary host command or Docker run was used.

## Deviations and remaining risks

- The handoff requested a verified local WSL PHP Wing Identity → Core HTTP v1.2.0 PASS record. The checked-in HTTP report describes that run as pending and no PASS output was available in this task context. Recording PASS would have overstated evidence. Run `bash scripts/wing-identity-core-http-integration.sh` from a Docker-capable WSL Core checkout to obtain that separate legacy-profile result.
- The current .NET actor issuer depends on EF-backed sessions and Auth authorization snapshots. This task did not change the read-only Identity repository to add a no-DB production-issuer test seam. The new fixture mirrors inspected claims but uses an ephemeral PHP RSA key.
- Core's parser remains stateless. A revoked/renewed Identity session's previously issued token remains usable until its expiry. Test DB-backed renewal/revocation and accepted logout latency.
- Identity's source exposes a current key and JWKS endpoint, but overlapping key rotation behavior was not demonstrated. Verify deployed key rollover and configure Core's static JWKS URL, issuer pin and URL allowlist deliberately.
- No .NET token-to-Core HTTP run, Docker image/runtime digest verification, WSL v1.2.0 run, or database-backed Identity lifecycle run is claimed.

## Definition of done

Core's source-level claim compatibility gaps are corrected and the bounded Core suite passes. Full Identity-to-Core runtime acceptance remains open until actual .NET-issued actor/service tokens pass Core HTTP validation and the WSL legacy harness and DB lifecycle checks are separately recorded.
