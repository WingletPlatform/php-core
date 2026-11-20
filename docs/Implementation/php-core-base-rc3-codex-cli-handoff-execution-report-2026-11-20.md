# Winglet PHP Core Base RC3 handoff execution report

**Date:** 2026-11-20  
**Repository:** `/opt/dev/repos/winglet/php-core` (`WingletPlatform/php-core`)  
**Handoff:** `Winglet-PHP-Core-Base-RC3-Codex-CLI-handoff.md`

## Starting state

The requested handoff was present in Downloads and names this repository as its target. Before this execution, the working tree already contained uncommitted changes to `composer.json` and `scripts/http-integration-smoke.sh`, plus an untracked report dated 2026-11-14. Those files were inspected and preserved. The existing changes already covered the GitHub homepage, Bitbucket environment removal, temporary Composer UID:GID, writable Composer home, bind-source checks, and cleanup error propagation.

## Changes made

- `scripts/http-integration-smoke.sh`: changed its default Base image from `akikettu/wingletbase:v0.4.0-RC1` to `akikettu/wingletbase:v0.4.0-RC3`; the `WINGLET_BASE_IMAGE` override remains.
- `scripts/wing-identity-core-http-integration.sh` and `scripts/dual-jwt-producer-http-integration.sh`: updated their functional Core image defaults to RC3. Historical documentation and RC1 release records were left intact.
- `.github/workflows/ci.yml`: changed both checkout steps from `actions/checkout@v4` to `actions/checkout@v5`. The workflow still uses `ubuntu-latest`; checkout v5 uses Node 24 and requires Actions Runner v2.327.1 or later, which is the GitHub-hosted runner compatibility documented by the action.
- `composer.json`: the existing GitHub homepage change was retained. Package name, license (`proprietary`), PHP constraint, authors, and dependencies were not changed.

## Verification

- `bash -n scripts/http-integration-smoke.sh` — passed.
- `git diff --check` — passed (Git emitted existing working-tree CRLF conversion warnings).
- `php vendor/bin/phpstan analyse -c phpstan.neon.dist` — passed; 173 files, no errors.
- `php vendor/bin/phpunit -c phpunit.xml.dist --display-all-issues` — passed; 201 tests, 795 assertions (PHP 8.5.9).
- `composer.json` JSON parsing and homepage/license assertions — passed.
- `composer validate --no-interaction --strict` — not run successfully because the Composer CLI is not installed in this environment (`composer: command not found`).
- YAML syntax validation — unavailable: PyYAML and other YAML parsers were not installed.
- `./scripts/http-integration-smoke.sh` — blocked before the live HTTP/CORS checks because the Docker daemon is unavailable or inaccessible. The temporary smoke directory was removed. The clean-checkout Docker Composer-install regression path was therefore not exercised.
- Docker Hub tag/image verification and `docker run ... php -v` — not possible without a Docker daemon. Docker Hub's tag detail/API was also inaccessible through the available web lookup. RC3 tag existence, PHP 8.5.13, required Composer tooling, Apache runtime, image digest, ownership behavior, and container cleanup remain unverified; no substitute tag was used.

## Final state and remaining release checks

No commit or push was made. The worktree includes the pre-existing changes and report noted above, plus this handoff's updates. `composer.json` remains `proprietary`; any license change remains an owner decision. Run the Docker image checks and both normal and no-vendor HTTP/CORS smoke paths in an environment with Docker, then confirm the real GitHub Actions run on `main`. No release or tag was created.
