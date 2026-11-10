# Winglet Core JWT v1 consumer implementation report

**Date:** 2026-09-05

## Implemented

- Standard JWT parsing now has an explicit canonical profile selected only by `typ=winglet+jwt`.
- Canonical tokens require RS256, exact configured issuer, `kid`, `iat`, `exp`, `token_context`, `session_version`, `sid`, and a string-array `privileges` claim.
- Canonical tenant scope, service-only identity, anonymous identity, end-user assurance, `auth_time`, and positive numeric tenant IDs are validated fail-closed.
- Old standard JWTs retain the configurable HS256/RS256 compatibility path for `idi`, `sid`, `jti`, `slv`, `groups`, `roles`, and historical service claim names. The frozen legacy parser was not changed.
- `AuthContext` exposes semantic actor, caller-service, session-version, tenant, assurance, and token-profile accessors. Existing compatibility accessors remain available.
- `PrivilegeMatcher` centralizes exact, namespace-wildcard, and full-wildcard matching.
- JWKS supports RSA `n`/`e` and optional `x5c`, cache reuse, controlled refresh for an unknown canonical `kid`, and stale-cache operation during fetch failures.

## Compatibility and rollout

Configure the expected issuer in `authentication.jwt.standard.issuer` (or `authentication.jwt.standard.canonical.issuer`) and provide RS256 public keys through `keys` or JWKS before Identity starts issuing canonical tokens. Existing standard-token configuration remains isolated from canonical validation; canonical tokens never use HS256, `aud`, roles, groups, `idi`, or `slv` semantics.

`AuthContext::getClientId()` remains as a compatibility alias for old applications. New code should use `getActorSubject()` / `getCallerServiceSubject()`, `getSessionVersion()`, `getTenantId()`, `getAssurance()`, and `getTokenProfile()`.

## Verification

- PHP syntax checks passed for the changed PHP files.
- PHPUnit could not be executed in the current checkout because `vendor/bin/phpunit` is not present. Run `composer install` followed by `composer test` in the dependency-enabled build environment.

## Remaining consumer audit

The Core repository still contains compatibility APIs and access-group handling for applications that consume old standard or frozen legacy tokens. Canonical authorization should use `AuthContext::hasPrivilege()` / `PrivilegeMatcher`; canonical tokens do not require roles or groups.
