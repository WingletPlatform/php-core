# Dual JWT real producer interoperability report

**Task:** `task-20261109T094114Z-c5703b3cf5d2`  
**Repository:** `wingletphp.core`  
**Starting Git state:** branch `main`, one commit ahead of `origin/main`; pre-existing modified and untracked dual JWT files were present.  
**Scope:** Core only for writes. No commits, pushes, releases, or deployments.

## Result

The requested real-producer HTTP interoperability matrix remains unverified. No Core source or test code was changed in this task. The existing dual JWT implementation and its uncommitted tests were preserved.

The existing report at `docs/Implementation/task-20261109T071915Z-a543d28286c3-dual-jwt-report.md` records synthetic JWT coverage and explicitly does not claim tokens from either real producer. It is a prior task artifact and was left unchanged.

## Producer and image evidence

The scoped Wing Identity Supervisor was compatible and connected. Its environment status reported rootless Docker and a local pinned image, `akikettu/wingng@sha256:27b425142fed4ee52eb4aa47ec3b09f6edd82dfe8d61d3518bf3083181afa762`, with a healthy disposable MariaDB test environment. This is observed Supervisor environment metadata; it does not establish that the image is `akikettu/wingng-identity:2026.2.1`. The approved registry resolver did not have metadata for the requested tag, so that tag's digest, labels, and runtime were not verified.

The Supervisor's `image.integrity` operation passed its configured repository image-integrity check, but did not report or verify the requested legacy tag.

The authorized `identity.full` operation failed at PHPUnit (exit 2). The registered source snapshot reported that `WingNg\Lib\Request\RequestHandlerFactory` was unavailable and directed the operator to `scripts/run-identity-prebuilt-core-tests.sh <pre-pulled-Core-image-reference>`. That source-snapshot path did not issue or expose an Identity session token. No bearer token or secret was captured.

No .NET producer source or test service was run. No canonical producer method, version, token, or HTTP response was observed.

## HTTP integration boundary

Core's normal-test catalog contains PHPUnit, PHPStan, and Composer validation only. Core has no enabled test profile for Docker, and no eligible dual-mode Docker HTTP harness was listed. The existing `scripts/wing-identity-core-http-integration.sh` is an older legacy Identity harness; it is not a dual-mode end-to-end test against both producers. The generic HTTP smoke script does not cover JWT producers.

The required follow-up artifact is `scripts/dual-jwt-producer-http-integration.sh`, run as `bash scripts/dual-jwt-producer-http-integration.sh` through a separately approved host-Docker verification gate. That harness must start or connect to isolated real producer services, configure the same Core protected HTTP endpoint with `authentication.jwt.mode=dual`, and assert the producer-specific 200/401/403 matrix without logging bearer values. No such script was added because the producer invocation contracts and a host-Docker gate are unavailable in this task; fabricating producer tokens would not satisfy the request.

Unverified cases include both real issuers in dual mode, standard-versus-legacy separation, exact and wildcard privileges, sufficient and insufficient session levels, anonymous `slv=0`, ignored roles, service principal support, canonical tenant context, legacy tenant-scope rejection, wrong key/signature/algorithm/type, expiration, and no-fallback behavior over HTTP.

## Core verification

All commands ran through task-scoped Bridge tools:

| Check | Result |
|---|---|
| `composer phpunit` | PASS — 201 tests, 795 assertions |
| `composer phpstan` | PASS — no errors |
| `composer validate --strict` | PASS — `composer.json` valid |
| `composer audit` | PASS — no security advisories found |
| Identity Supervisor `image.integrity` | PASS — configured repository check only |
| Identity Supervisor `identity.full` | FAIL — source-snapshot PHPUnit missing required Core compatibility class |
| Real-producer dual HTTP matrix | NOT RUN — no approved host-Docker catalog entry and no real producer token issuance |

These Core checks validate the existing repository state; they do not substitute for real-producer HTTP interoperability.

## Files and scope

Created this report only. Existing implementation, tests, configuration changes, and prior report remain as found. No other repository was modified.

## Remaining gate

Enable or provide a separately approved host-Docker verification profile for the Core dual-producer harness. Resolve the producer-side test invocations, verify the exact `2026.2.1` image by digest and runtime, then run the real producer HTTP matrix and append observed evidence. Until then, release acceptance for actual issuer interoperability is unmet.
