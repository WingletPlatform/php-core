# Winglet.PHP Core README handoff execution report

**Date:** 2026-11-20  
**Repository:** `WingletPlatform/php-core`  
**Handoff:** `Winglet-PHP-Core-README-Codex-CLI-handoff.md`

## Result

Added a root `README.md` describing Core, its separation from the Base runtime, requirements, current-source installation, a controller and HTTP entry-point excerpt, implemented capabilities, configuration layers, database setup, development checks, CI, release links, and license status.

The current repository declares `winglet/core`, PHP `>=8.5 <8.6`, `ext-openssl`, and `ext-pdo`. Packagist has a `winglet/core` package entry, but it reports v0.12.2, PHP `^8.4`, an old Bitbucket source URL, and last update 2026-08-01. For current GitHub `main`, README gives a Composer VCS repository example pinned to `dev-main` instead of implying that the Packagist entry matches this checkout. The lookup is available at [Packagist winglet/core](https://packagist.org/packages/winglet/core).

The README states the code's actual configuration stack (`resources/config`, `App/resources/config`, then runtime `config`) and that `Env::fromDotenvIfPresent()` does not parse `.env`. It records `proprietary` and the absence of a root `LICENSE` file without assigning a different license.

## Verification

- Extracted both PHP examples and ran `php -l` on each — passed.
- Checked relative Markdown links against the working tree — passed.
- Checked the new README for whitespace errors — passed.
- No PHPStan, PHPUnit, application boot, Composer installation, or Markdown linter was run; this is a documentation-only change and no Markdown linter was available in the environment.
- GitHub pages could not be fetched by the available web lookup. The Core repository URL matches the configured Git remote; Base and release URLs are the supplied repository paths.

## Open documentation discrepancies

- `docs/Configuration/FileConfiguration.md` describes application defaults under `/var/www/App/config`; current `Bootstrapper` reads them from `/var/www/App/resources/config` and has framework defaults as an additional first layer. This was documented accurately in README and the older file was left unchanged.
- The `Env` helper's dotenv-named method is currently a no-op for file parsing. README calls out that environment values must be populated for PHP before boot.
- No root `LICENSE` file exists, while Composer says `proprietary`; redistribution terms remain an owner question.
- The Docker HTTP/CORS smoke's default RC3 image was not pulled or run as part of this README task; Docker availability was not rechecked here.

## Git state

Only documentation files were added by this task: `README.md` and this report. Pre-existing CI, Composer, and smoke-script changes remain untouched. No commit, push, tag, or release was made.
