# Readable JWT validation before Core RC

## Summary

Reformatted `StandardJwtTokenService` for readability while preserving the current canonical `winglet+jwt` and legacy standard JWT validation paths, claim checks, verification order, and error behavior.

## Changes

- Expanded compressed conditions, loops, method bodies, and `AuthContext` construction into readable blocks.
- Kept the change within `src/Security/Auth/Jwt/StandardJwtTokenService.php`; no migration documentation or auth policy changes were needed.

## Verification

- `composer phpunit`: passed, 166 tests and 661 assertions.
- `composer phpstan`: passed, zero errors.
- `composer validate --strict`: passed; `composer.json` is valid.
- `git diff --check -- src/Security/Auth/Jwt/StandardJwtTokenService.php`: passed.

## Deviations and risks

- The repository already had numerous unrelated local modifications when this task began. They were left intact. The target file also had pre-existing behavior changes matching this task's canonical and legacy validation scope; this task preserved that working-tree implementation and changed its formatting only.
- No known behavior deviation. The full PHPUnit suite, PHPStan, strict Composer validation, and whitespace check passed.

## Definition of done

- Only the requested JWT service was changed for implementation; this report was added for task completion tracking.
- No commit, tag, push, or publish was performed.
