# Winglet Core – Doctrine Configuration Guide

This document explains how Doctrine ORM is configured in **Winglet Core**, how entity mapping paths are defined, and how applications or environment instances can override defaults.

---

# 1. Configuration Layers

Doctrine configuration follows the standard Winglet Core configuration stack:

1. **Framework defaults** (shipped with Winglet Core)
2. **Application defaults** (`/var/www/App/config`)
3. **Instance overrides** (`/var/www/config`, optional)

Precedence (last wins):

```
Instance overrides
    ↓
Application defaults
    ↓
Framework defaults
```

Configuration arrays are merged using `array_replace_recursive()`.

---

# 2. Default Configuration Structure

The Doctrine configuration structure looks like this:

```php
return [
    'doctrine' => [
        'enabled' => true,

        'connection' => [
            'driver' => 'pdo_mysql',
            'host' => 'localhost',
            'port' => 3306,
            'dbname' => '',
            'user' => '',
            'password' => '',
            'charset' => 'utf8mb4',
        ],

        'dev_mode' => true,
        'auto_generate_proxies' => true,
        'proxy_dir' => '/var/www/cache/doctrine/proxies',

        'mapping' => [
            'type' => 'attribute',
            'paths' => [
                '/var/www/App/src/Entities'
            ],
            'namespaces' => [
                'App\\Entities'
            ],
        ],

        'cache' => [
            'metadata' => 'array',
            'query' => 'array',
        ],
    ],
];
```

---

# 3. Database Connection Settings

The `connection` section maps directly to Doctrine DBAL parameters.

Example using environment variables:

```php
'connection' => [
    'driver' => $_ENV['DB_DRIVER'] ?? 'pdo_mysql',
    'host' => $_ENV['DB_HOST'] ?? 'localhost',
    'port' => (int)($_ENV['DB_PORT'] ?? 3306),
    'dbname' => $_ENV['DB_NAME'] ?? '',
    'user' => $_ENV['DB_USER'] ?? '',
    'password' => $_ENV['DB_PASS'] ?? '',
    'charset' => 'utf8mb4',
],
```

### Recommended Production Practice

* Never hardcode credentials
* Use environment variables or Docker secrets
* Override connection values via `/var/www/config/database.php`

---

# 4. Entity Mapping Configuration

## 4.1 Default Mapping

By default, Winglet Core assumes:

```
/var/www/App/src/Entities
```

With namespace typically defined in the application:

```json
"autoload": {
  "psr-4": {
    "App\\": "src/"
  }
}
```

Example entity:

```php
namespace App\Entities;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class User
{
}
```

---

## 4.2 Mapping Type

Supported mapping types:

* `attribute` (recommended, modern default)
* `annotation`
* (future) `xml`
* (future) `yml`

Example:

```php
'mapping' => [
    'type' => 'attribute',
    'paths' => ['/var/www/App/src/Entities'],
]
```

---

## 4.3 Multiple Mapping Paths

Applications may define multiple entity directories:

```php
'mapping' => [
    'type' => 'attribute',
    'paths' => [
        '/var/www/App/src/Entities',
        '/var/www/App/modules/Billing/Entities'
    ],
]
```

This is useful for modular or domain-driven designs.

---

# 5. ORM Development Mode

```php
'dev_mode' => true
```

### In development

* Proxy classes auto-generated
* Metadata cache often disabled or array-based

### In production

* Set `dev_mode` to `false`
* Disable auto proxy generation
* Use proper metadata/query caching

---

# 6. Caching Configuration

```php
'cache' => [
    'metadata' => 'array',
    'query' => 'array',
]
```

Supported strategies (implementation-dependent):

* `none`
* `array` (in-memory, dev only)
* `psr6` (recommended for production)
* `psr16`

Production environments should use a persistent cache backend.

---

# 7. Overriding Configuration Per Environment

To override defaults for a specific deployment, create:

```
/var/www/config/database.php
```

Example override:

```php
return [
    'doctrine' => [
        'connection' => [
            'host' => 'prod-db',
            'password' => $_ENV['DB_PASSWORD'],
        ],
    ],
];
```

Only specified keys are replaced; other values remain unchanged.

---

# 8. Recommended Production Settings

Example production configuration:

```php
return [
    'doctrine' => [
        'orm' => [
            'dev_mode' => false,
            'auto_generate_proxies' => false,
            'cache' => [
                'metadata' => 'psr6',
                'query' => 'psr6',
            ],
        ],
    ],
];
```

---

# 9. Design Principles

Winglet Core Doctrine integration is designed to:

* Keep the framework namespace-independent
* Assume sensible default entity location
* Allow full override without framework modification
* Separate configuration from code
* Support layered configuration (framework → app → instance)

---

# 10. Summary

When creating a new application:

1. Define your database connection in config.
2. Ensure entities are placed in `/src/Entities` or configure mapping paths.
3. Set production cache settings.
4. Override secrets via environment or instance config.

Doctrine configuration remains flexible, predictable, and environment-safe under Winglet Core.
