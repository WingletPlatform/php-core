# PHP 8.5 Core security verification follow-up

Date: 2026-10-25  
Repository: `wingletphp.core`

## Summary

Added an isolated GH-24488 regression check to the existing Docker HTTP smoke. It exercises Core's `Response::json()` path with a `SimpleXMLElement` in a cyclic object graph in a separate PHP CLI process inside the disposable Base container. A crash can terminate only that child process; it does not run in the Bridge host or Apache worker. The existing smoke still checks HTTP errors and follow-up traffic, Apache response headers, ordinary OPTIONS, and default-off/configured exact-origin CORS.

The smoke is now part of default, pull-request, and tag pipelines using the existing script and a Docker service. The smoke's Base image remains `akikettu/wingletbase:v0.4.0-RC1` unless `WINGLET_BASE_IMAGE` overrides it.

## SimpleXML and compatibility findings

- There is no SimpleXML/SimpleXMLElement use or SimpleXML dependency in Core source.
- Core does use `json_encode()` in `Response::json(array $data)`. Applications can supply objects as array values; therefore an application-provided SimpleXML object in a reference cycle can reach the affected engine path. The isolated smoke exercises that conditional path. Core itself does not construct SimpleXML values or cyclic response graphs.
- `get_object_vars()` occurs in legacy JWT config normalization; other object-to-array casts are config normalization. No SimpleXML pathway was found there.
- The reported [php-src GH-24488 issue](https://github.com/php/php-src/issues/24488) affects PHP 8.4.27/8.5.12 when SimpleXML property materialization and a collected reference cycle combine. An [approved upstream fix](https://github.com/php/php-src/pull/24494) is recorded; this task did not establish that a released official image contains it. Monitor PHP bug-fix releases and the Docker Official Image before removing the regression check.
- Existing legacy Wing Identity and canonical .NET JWT compatibility paths were not changed.

## Verification

| Check | Result |
| --- | --- |
| PHPUnit | PASS: 166 tests, 661 assertions, PHP 8.5.9. |
| PHPStan | PASS: 168 files, no errors. |
| Composer validation | PASS: `composer validate --strict`. |
| User-reported WSL run (2026-10-25) | Script v1.2.0 ran against `akikettu/wingletbase:v0.4.0-RC1`, digest `sha256:93b65a8dec5c530bac42ec800102185f3ecf3f6d15b3ff8e73d9539ef5362c6d`, with PHP 8.5.12. The default HTTP smoke passed. `--simplexml-info` ran the isolated child probe, which aborted with `zend_mm_heap corrupted` (exit 134), was reported as `KNOWN FAIL`, and did not block the main HTTP smoke from passing. This confirms the known upstream GH-24488 risk on that image; Core does not fix it. |
| Bridge checks | PHPUnit (166 tests, 661 assertions), PHPStan, and `composer validate --strict` passed in this task. Docker HTTP and isolated GH-24488 checks were not run in this Bridge task. |
| Docker/Base runtime version actually tested | None. The configured default Base image reference is `akikettu/wingletbase:v0.4.0-RC1`, not proof that its runtime was tested here. |
| Composer version | Not reported by the bounded validation gate. |

Minimal WSL command for the Docker smoke after its Docker host gate is approved:

```sh
cd /home/akikettu/dev/repos/winglet.php/core
WINGLET_BASE_IMAGE=akikettu/wingletbase:v0.4.0-RC1 ./scripts/http-integration-smoke.sh
```

## Changes, deviations, and residual risks

- `scripts/http-integration-smoke.sh` contains the isolated regression fixture and is versioned 1.1.3.
- `bitbucket-pipelines.yml` adds the Docker-backed smoke to default, PR, and tag CI.
- The test may expose GH-24488 in a Base image still running PHP 8.5.12. This is an upstream runtime defect, not repaired by a Core API change. The test is expected to pass only when the actual container PHP build includes the fix; no version bump or Base image change was made here.
- No API semantics, CORS defaults, authentication behavior, or token formats changed.

## Files

- `scripts/http-integration-smoke.sh`
- `bitbucket-pipelines.yml`
- `docs/php2shell-security-verification-2026-10-25.md`
