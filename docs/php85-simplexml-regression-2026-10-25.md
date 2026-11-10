# PHP 8.5.12 SimpleXML regression check

Date: 2026-10-25  
Repository: `wingletphp.core`

## Observed WSL result

The user ran HTTP smoke v1.2.0 against:

```sh
WINGLET_BASE_IMAGE=akikettu/wingletbase:v0.4.0-RC1 ./scripts/http-integration-smoke.sh
WINGLET_BASE_IMAGE=akikettu/wingletbase:v0.4.0-RC1 ./scripts/http-integration-smoke.sh --simplexml-info
```

The image was `akikettu/wingletbase@sha256:93b65a8dec5c530bac42ec800102185f3ecf3f6d15b3ff8e73d9539ef5362c6d` with PHP 8.5.12. The default HTTP smoke passed. In informational mode, the isolated SimpleXML fixture child CLI process aborted with `zend_mm_heap corrupted` (exit 134) and was marked `KNOWN FAIL`; the main HTTP smoke still passed. This matches the upstream PHP GH-24488 regression. Core did not cause or fix the PHP allocator failure, and no production PHP behavior or memory limit was changed. This result is user-reported WSL evidence; Docker was not run in this Bridge task.

## Commands and result meanings

Run from the Core repository root with Docker available:

```sh
# Required route, HTTP security, and CORS checks; independent of GH-24488.
./scripts/http-integration-smoke.sh

# Run the isolated GH-24488 probe after HTTP checks; report KNOWN FAIL without
# blocking unrelated changes when the candidate image still has the regression.
WINGLET_BASE_IMAGE=akikettu/wingletbase:v0.4.0-RC1 ./scripts/http-integration-smoke.sh --simplexml-info

# Explicit blocking evaluation for a candidate PHP image expected to contain
# the upstream fix. Exit status is nonzero if the regression probe fails.
WINGLET_BASE_IMAGE=<candidate-fixed-image> ./scripts/http-integration-smoke.sh --simplexml-strict
```

The informational mode reports the probe's output and exit status as `KNOWN FAIL`; it does not call the PHP issue fixed. Strict mode fails the command when the probe fails. Both modes execute the standard HTTP/CORS assertions first. The probe runs in a child CLI process inside the disposable container, separate from Apache and the host.

## Changes

- Removed the SimpleXML probe from the default HTTP smoke so an upstream CLI crash cannot prevent route/security/CORS checks.
- Added the explicit `--simplexml-info` and `--simplexml-strict` modes to `scripts/http-integration-smoke.sh`.
- No Core production code, API, or runtime configuration changed.

## Verification

- Core bounded `composer phpunit`: passed, 166 tests and 661 assertions, PHP 8.5.9.
- Core bounded `composer phpstan`: passed, no errors.
- Core bounded `composer validate --strict`: passed.
- Docker HTTP and SimpleXML probes were not run in this Bridge task. Use the WSL commands above to verify the container behavior.

## Risk and status

PHP 8.5.12 remains known to fail the GH-24488 reproducer on the user-reported image. Upstream PR 24494 was closed after a fix commit was linked on October 22, 2026, but this task did not verify that a released PHP build or candidate Docker image contains that fix. Run strict mode against the selected image before considering the regression cleared. The normal HTTP smoke remains a blocking CI step and executes independently. See the [GH-24488 issue](https://github.com/php/php-src/issues/24488) and [fix PR](https://github.com/php/php-src/pull/24494).
