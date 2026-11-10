# Winglet – AI Context & Architecture Notes

This file describes architectural decisions and environment assumptions for the Winglet framework.
The purpose of this file is to give AI assistants and developers the correct mental model of the system
without needing to re-analyze the entire codebase every time.

## 1. Runtime vs Application Code

In Winglet, the application code and runtime data are strictly separated.

Application code is located in:

    /var/www/App

This directory is treated as **read-only application code** at runtime.
The only directory that may be created or modified inside App is:

    /var/www/App/vendor

Everything else must be treated as immutable code.

All runtime-generated files must go to:

    /var/www/var

This includes:
- cache files
- Doctrine proxies
- logs
- temporary files
- generated route/controller maps
- any other writable runtime data

This is a strict architectural rule.

## 2. Runtime Root vs App Root

Winglet runs with two different roots:

- runtime root: `/var/www`
- app root: `/var/www/App`

Configuration paths that point to generated runtime artifacts should resolve under the runtime root.
Relative paths such as `var/cache/doctrine` and `var/doctrine/proxies` are intended to resolve to
`/var/www/var/...` in the default Docker layout, not to `/var/www/App/var/...`.

## 3. Single App Per Container

Each Docker container runs exactly **one Winglet application**.
Because of this:
- Runtime directories do NOT need app-name separation
- Shared runtime directories are safe inside the container

Runtime directory structure:

    /var/www/var/cache/doctrine
    /var/www/var/doctrine/proxies
    /var/www/var/log
    /var/www/var/tmp

## 4. File Permissions Model

The system must support file creation and modification via:
- HTTP requests (www-data user)
- CLI commands
- cron jobs

To ensure files remain writable regardless of who created them:

Directories:
- owner: www-data
- group: www-data
- permissions: 2775 (setgid)

Files:
- permissions: 0664

All CLI scripts should use:

    umask 0002

This ensures group-write permissions always work.

## 5. Doctrine ORM Version & Mapping Rules

Winglet targets Doctrine ORM 3.x.
Important consequences:

- `doctrine/cache` must not be used by framework code
- PSR-6 caches are the canonical cache API for Doctrine integration
- `EntityManagerInterface::transactional()` is not used; use `wrapInTransaction()`
- annotation mapping is not supported
- YAML mapping is not supported
- attribute mapping is the default and preferred mapping mode
- app code must not rely on `merge()`, `flush($entity)`, `clear(SomeEntity::class)`, or `Query::iterate()`

If application code uses Doctrine lifecycle/event classes, it must use the ORM 3-compatible event args.
For example, code must not rely on removed classes such as `LifecycleEventArgs`.

## 6. Doctrine Cache & Proxies

Doctrine must NOT write anything inside `/var/www/App`.

Doctrine paths:

Metadata/Query/Result cache:

    /var/www/var/cache/doctrine

Doctrine proxies:

    /var/www/var/doctrine/proxies

These paths are configured via framework configuration.

## 7. PSR Cache Architecture

Winglet uses:
- PSR-6 (primary, used by Doctrine)
- PSR-16 (optional, via adapter)

The framework must NOT depend directly on:

    doctrine/cache

Even if the package exists temporarily as a transitive dependency during an upgrade path,
the framework code must only use:
- PSR-6
- PSR-16
- Symfony Cache adapters

## 8. Configuration Philosophy

Framework provides defaults, but everything must be configurable.

Doctrine cache config supports two formats:

Short format:

    doctrine.cache.metadata = "array"

Canonical format:

    doctrine.cache.metadata = {
        driver: "filesystem",
        directory: "var/cache/doctrine",
        default_ttl: 0
    }

Both formats should remain supported for backward compatibility where practical.

## 9. Configuration Loading Order

Winglet configuration is layered.
Runtime/deployment-level configuration under `/var/www/config` is separate from application source code.
Do not assume that all configuration lives inside the app repository.

When changing configuration defaults, keep in mind that deployment-level configuration may override them.

## 10. Generated Routing Artifacts

Controller maps and route maps are runtime-generated artifacts.
They belong under `/var/www/var`, not under `/var/www/App`.
Any tool, controller, or CLI command that rebuilds routing metadata must write only to runtime paths.

## 11. Request/Base Path Behavior

Winglet has logic for request `basePath` detection and path normalization.
Changes to routing, controller discovery, dev tools, or generated route maps should preserve this behavior.
Do not assume the app always runs at URL root.

## 12. Authentication/JWT Compatibility Notes

Winglet contains JWT/auth code with compatibility concerns between legacy and standard token handling.
When modifying auth-related code, verify whether the change affects:
- token format compatibility
- identity service expectations
- middleware assumptions
- config defaults in auth/runtime config files

Do not silently break token compatibility without explicitly documenting the change.

## 13. Important Rule for Future Changes

When modifying the framework:

- Never write runtime files into `/var/www/App`
- Always write runtime files into `/var/www/var`
- Ensure CLI, cron, and HTTP can modify the same files
- Use PSR cache interfaces, not vendor-specific cache APIs
- Do not introduce hidden writable directories inside App
- Prefer framework-managed infrastructure over app-specific bootstrap workarounds

## 14. Key Design Principles

Winglet architecture is based on:

- Immutable application code
- Separate runtime directory
- Framework-managed infrastructure (Doctrine, routing, cache)
- Applications should not need to know where cache is stored
- Environment must behave the same in dev and production
