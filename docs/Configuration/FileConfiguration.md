# Configuration Layout & Merge Behavior

This document describes how configuration files are structured and merged when using **Winglet Core** with default (app-level) configuration and environment-specific overrides.

---

## Recommended Config File Layout

### Defaults (committed with the application)

Location:

```
/var/www/App/config/
```

Typical files:

* `app.php`
* `framework.php`
* `auth.php`
* `logging.php`
* `database.php`

These files:

* Are part of the application repository
* Contain sane defaults and non-sensitive values
* May reference environment variables for secrets

Example:

```php
<?php
return [
    'environment' => [
        'Secret' => $_ENV['APP_SECRET'] ?? 'dev-secret'
    ],
];
```

---

### Environment / Instance Overrides (not committed)

Location:

```
/var/www/config/
```

Typical files:

* `framework.php`
* `database.php`
* `logging.php`

These files:

* Are mounted or injected per environment (Docker volume, secrets, etc.)
* Override only the values that differ from defaults
* Should contain sensitive or environment-specific settings

Example override:

```php
<?php
return [
    'database' => [
        'connection' => [
            'host' => 'prod-db',
            'password' => $_ENV['DB_PASSWORD']
        ]
    ]
];
```

---

## Important Notes About Merge Behavior

### Merge strategy

* Configuration is merged using `array_replace_recursive()`
* **Later-loaded sources override earlier ones**

Effective precedence:

1. `/var/www/App/config` (defaults)
2. `/var/www/config` (environment overrides)

---

### File loading order

* All `*.php` files in a directory are loaded
* Files are processed in **alphabetical order**

Example ordering:

```
01_app.php
10_database.php
20_logging.php
```

This allows deterministic layering if needed.

---

### Partial overrides

Only the keys you specify in override files replace defaults.

Default:

```php
'logging' => [
    'driver' => 'stdout',
    'level' => 'debug'
]
```

Override:

```php
'logging' => [
    'level' => 'error'
]
```

Result:

```php
'logging' => [
    'driver' => 'stdout',
    'level' => 'error'
]
```

---

### Missing directories

* If `/var/www/config` does not exist, it is silently ignored
* Defaults from `/var/www/App/config` are still loaded

This makes local development and production setups symmetrical.

---

### Design goal

This configuration model ensures:

* Clear separation between **code** and **runtime configuration**
* Safe defaults committed to the repository
* Clean, override-only environment configuration
* Zero conditional logic in application code

---

## HTTP-layer configuration namespace

HTTP request-pipeline settings should be configured under `http.*`. CORS uses `http.cors.*`; access-log behavior, session request behavior, trusted proxy handling, and reserved future namespaces for rate limiting and security headers follow the same structure.

See [`HttpConfiguration.md`](HttpConfiguration.md) for the current structure and deprecated alias mapping.
