# Winglet Config Stack Migration Review Guide

## Purpose

This guide is intended for AI-assisted review of existing Winglet applications after standardizing the configuration stack to exactly three layers:

```text
1. Framework defaults
2. Application defaults
3. Instance overrides
```

The goal is to remove ambiguous or duplicate configuration behavior and make every setting live in the correct layer.

---

# Target Configuration Model

## 1. Framework defaults

Location:

```text
vendor/winglet/core/resources/config
```

Purpose:

Framework-level defaults that are the same for all Winglet applications.

Examples:

```text
default HTTP/kernel behavior
default logging structure
default route builder behavior
default middleware behavior
default Doctrine integration defaults, if framework-owned
```

Framework defaults must not contain application-specific namespaces, controller paths, service names, database credentials, auth audiences, tenant IDs, or deployment-specific URLs.

---

## 2. Application defaults

Location:

```text
App/resources/config
```

Purpose:

Defaults that belong to the application/service package and should be the same for every instance of that service.

Examples:

```text
application name
controller namespace
controller source path relative to App
Doctrine entity namespace
Doctrine entity paths
domain defaults
default feature configuration
default validation limits
default route/controller discovery configuration
OpenAPI metadata
```

Example:

```php
return [
    'controllers' => [
        'app' => [
            'namespace' => 'Winglet\\Notes\\Controller\\',
            'baseDir' => __DIR__ . '/../../src',
            'subDir' => 'Controller',
        ],
    ],
];
```

Application defaults must not contain database host, database name, database username, database password, production tokens, tenant-specific values, or environment-specific public URLs.

---

## 3. Instance overrides

Location:

```text
/var/www/config
```

Purpose:

Values that differ between deployments, environments, customers, tenants, or runtime instances.

Examples:

```text
database DSN
database host
database name
database username
database password
auth issuer
auth audience
auth token mode
external service base URLs
public base URL
single tenant default tenant ID
instance-specific feature flags
secrets
```

Instance config should be small. It should only override values that must differ from the application defaults.

---

# Required Framework Behavior

The framework bootstrap should load configuration in this order:

```text
framework defaults
app resources/config
instance /var/www/config
```

Later layers override earlier layers.

There should be no fourth custom layer and no ambiguous reuse of `/var/www/config` as both application defaults and instance overrides.

Recommended conceptual behavior:

```php
$config = Config::loadStack(
    frameworkDefaultsDir: $frameworkDefaults,
    appDefaultsDir: $appRoot . '/resources/config',
    instanceOverridesDir: $basePath . '/config'
);
```

If explicit custom config paths exist in the current framework, they should be removed unless there is a strong, documented runtime requirement.

---

# AI Review Task

For each existing Winglet application, review the project and classify every config file and config key into one of the following categories:

```text
framework default
application default
instance override
obsolete / remove
wrong layer / move
```

The review should produce:

```text
1. list of files to keep
2. list of files to move
3. list of files to remove
4. list of keys to remove
5. list of keys that must be added to App/resources/config
6. list of keys that must remain only in /var/www/config
7. migration risk notes
```

---

# Review Checklist

## A. Check application default config

Inspect:

```text
App/resources/config
```

Verify that it contains all application-level defaults required to run the service.

Typical required files:

```text
10_framework.php
app.php
auth.php
doctrine.php
logging.php
<domain>.php
```

Not every app needs every file, but missing framework/controller or Doctrine config should be treated as suspicious if the app has controllers or Doctrine entities.

---

## B. Check instance config

Inspect:

```text
/var/www/config
```

The instance config should contain only environment-specific overrides.

Typical allowed files:

```text
15_http.php
20_database.php
40_auth.php
80_<domain>.php
```

The exact names may vary, but the content must be instance-specific.

Remove files that only duplicate App/resources/config defaults.

---

## C. Check controller discovery

Find controller namespace and source path configuration.

Correct layer:

```text
App/resources/config
```

Usually incorrect layer:

```text
/var/www/config
```

The app package knows its own namespace and controller directory. The instance should not normally define these.

Expected example:

```php
'controllers' => [
    'app' => [
        'namespace' => 'Winglet\\Notes\\Controller\\',
        'baseDir' => __DIR__ . '/../../src',
        'subDir' => 'Controller',
    ],
],
```

Verify:

```text
namespace matches actual controller namespace
baseDir resolves to App/src
subDir points to Controller
route builder finds all expected controllers
```

Command:

```bash
winglet routes:build
```

---

## D. Check Doctrine config

If the app uses Doctrine entities, verify that App/resources/config contains Doctrine defaults.

Correct layer:

```text
App/resources/config/doctrine.php
```

Expected content:

```text
entity paths
entity namespaces
proxy directory
proxy namespace
migration paths
```

Incorrect in app defaults:

```text
database host
database name
database user
database password
```

Those belong to instance config.

Verify:

```bash
winglet-doctrine orm:clear-cache:metadata
winglet-doctrine update --dump-sql
```

---

## E. Check database config

Database connection details must remain instance-specific.

Correct layer:

```text
/var/www/config
```

Examples:

```text
host
port
database name
username
password
DSN
driver
```

Application packages must not ship production database credentials or instance database names in App/resources/config.

---

## F. Check auth config

Classify each auth setting carefully.

Application default examples:

```text
required auth mode defaults
permission names
default auth behavior
```

Instance override examples:

```text
issuer
audience
JWKS URL
legacy token mode
service tokens
trusted service secrets
```

Secrets must always be instance-specific.

---

## G. Check HTTP/base URL config

Instance-specific:

```text
public base URL
internal base URL
route prefix caused by deployment path
CORS allowed origins
```

Application default:

```text
default API path only if it is truly part of the app contract
```

Avoid duplicating route prefixes if controllers already define full route paths.

---

## H. Check domain config

Domain config should be split as follows.

Application defaults:

```text
validation limits
allowed states
default category lists
default timeout values
default feature disabled/enabled values
```

Instance overrides:

```text
customer-specific tenant ID
integration target URLs
customer-specific enabled features
secrets
runtime operational limits
```

---

# Files That Commonly Need Moving

## Move from /var/www/config to App/resources/config

```text
controller discovery config
application namespace config
Doctrine entity namespace/path config
domain default validation limits
default enum/state labels if app-owned
```

## Keep in /var/www/config

```text
database config
public/internal base URLs
auth issuer/audience/secrets
service client URLs
tenant-specific values
```

## Remove

```text
empty placeholder configs
unused webhook configs
example/skeleton configs
duplicate app defaults
configs for unrelated services
hardcoded test-only secrets
```

---

# Detection Rules for AI Review

Mark a config key as suspicious if:

```text
it contains App or service namespace inside /var/www/config
it contains /var/www/App/src inside /var/www/config
it defines Doctrine entity namespace outside App/resources/config
it contains DB credentials inside App/resources/config
it contains placeholder webhook secrets
it references Example, AppSkeleton or old service names
it defines routePrefix that duplicates controller route prefixes
it defines config for a domain not used by the service
```

---

# Required Output Format

For each reviewed application, produce this report:

```markdown
# Config Migration Review: <Application Name>

## Summary

- Status: OK / Needs changes / Broken
- Main issue:
- Migration risk:

## Keep in App/resources/config

| File | Reason |
|---|---|

## Add to App/resources/config

| File | Reason |
|---|---|

## Move to App/resources/config

| Current file | Target file | Reason |
|---|---|---|

## Keep in /var/www/config

| File | Reason |
|---|---|

## Remove from /var/www/config

| File | Reason |
|---|---|

## Remove from App/resources/config

| File | Reason |
|---|---|

## Required Commands After Changes

```bash
cd /var/www/App
composer dump-autoload

cd /var/www
winglet routes:build
winglet-doctrine orm:clear-cache:metadata
winglet-doctrine update --dump-sql
```

## Manual Checks

- [ ] Route builder finds expected controller count
- [ ] Doctrine sees expected entity mappings
- [ ] App starts without missing config
- [ ] Instance config has no app-default duplicates
- [ ] App resources config has no secrets
```

---

# Notes Service Expected Config After Migration

## App/resources/config

Expected:

```text
10_framework.php
app.php
auth.php
doctrine.php
logging.php
notes.php
```

## /var/www/config

Expected instance-level files:

```text
15_http.php
20_database.php
40_auth.php
80_notes.php
```

## Not expected

Unless explicitly needed:

```text
webhooks.php
bootstrap.php
10_framework.php in /var/www/config
70_doctrine.php in /var/www/config
```

---

# Final Principle

When deciding where a config belongs, use this rule:

```text
If the value is true for every instance of the application, it belongs in App/resources/config.
If the value changes per deployment, tenant, customer, server, secret, or environment, it belongs in /var/www/config.
If the value is true for every Winglet application, it belongs in the framework defaults.
```
