# Application Structure for Apps Built on Winglet\Core

This document describes a **recommended file and directory structure** for applications built on top of the **Winglet\Core** framework.  
The goal is to keep **framework responsibilities and application responsibilities clearly separated**, while allowing apps to scale cleanly over time.

---

## High-Level Principles

- **Winglet\Core** handles:
    - HTTP lifecycle
    - Middleware pipeline
    - Authentication & authorization
    - Doctrine initialization
    - Logging, configuration, error handling

- **Application** handles:
    - Controllers
    - Routes
    - Business logic
    - Domain rules
    - Infrastructure integrations

- **public/** is the *only* web-exposed directory
- Business logic must not depend on HTTP or framework internals

---

## Recommended App Folder Structure

```
app/
├─ bin/
│ └─ console # Optional CLI entrypoint
├─ config/
│ ├─ app.php # App-specific configuration
│ ├─ framework.php # Winglet\Core overrides
│ ├─ doctrine.php # Doctrine mappings & DB config
│ ├─ routes.php # Route definitions
│ └─ security.php # Auth/access rules (optional)
├─ public/
│ └─ index.php # HTTP entrypoint (Docker/web server)
├─ src/
│ ├─ Application.php # App bootstrap hook
│ ├─ Controller/
│ │ ├─ HealthController.php
│ │ ├─ AuthController.php
│ │ └─ Admin/
│ │ └─ UserController.php
│ ├─ Domain/
│ │ ├─ User/
│ │ │ ├─ Entity/
│ │ │ ├─ Repository/
│ │ │ ├─ Service/
│ │ │ └─ Policy/
│ │ └─ Order/
│ │ └─ ...
│ ├─ ApplicationService/
│ │ ├─ UserService.php
│ │ └─ AuthService.php
│ ├─ Infrastructure/
│ │ ├─ Persistence/
│ │ │ ├─ Doctrine/
│ │ │ │ ├─ UserRepository.php
│ │ │ │ └─ Mapping/
│ │ │ └─ Migration/
│ │ ├─ Security/
│ │ │ └─ CustomVoter.php
│ │ ├─ Http/
│ │ │ └─ Middleware/
│ │ └─ External/
│ │ ├─ PaymentGateway/
│ │ └─ Email/
│ ├─ DTO/
│ │ ├─ Request/
│ │ └─ Response/
│ ├─ Validator/
│ ├─ Exception/
│ └─ Support/
│ ├─ Helpers.php
│ └─ Constants.php
├─ resources/
│ ├─ migrations/
│ ├─ fixtures/
│ └─ templates/ # HTML/email templates if ever needed
├─ tests/
│ ├─ Unit/
│ ├─ Integration/
│ └─ Functional/
├─ var/
│ ├─ cache/
│ ├─ log/
│ └─ tmp/
├─ vendor/
├─ composer.json
├─ composer.lock
├─ phpunit.xml.dist
├─ phpstan.neon
├─ phpcs.xml
└─ README.md
```

---

## Directory Responsibilities

### `public/`
- Only directory exposed to the web server
- Contains `index.php` front controller
- Calls Winglet\Core kernel
- No business logic

---

### `config/`
Configuration-only files:
- No logic
- Easy environment overrides
- Clear separation from code

Typical contents:
- `framework.php` → Winglet\Core settings
- `routes.php` → route → controller mapping
- `doctrine.php` → entities, DB connection
- `security.php` → optional auth/access rules

---

### `src/Controller/`
Thin HTTP controllers:
- Read `Request` and `AuthContext`
- Call application services
- Return arrays / DTOs

Controllers should **not**:
- Contain business logic
- Query database directly
- Perform authorization logic manually

---

### `src/Domain/`
Pure business logic:
- Entities
- Domain services
- Policies
- Repositories (interfaces)

Rules:
- No framework imports
- No HTTP concepts
- Doctrine attributes are allowed

This layer is:
- Testable
- Reusable
- Framework-independent

---

### `src/ApplicationService/`
Use-case orchestration layer:
- Called by controllers
- Coordinates domain services
- Implements application-level workflows

Example flow:


Controller → ApplicationService → Domain


---

### `src/Infrastructure/`
Technical implementations:
- Doctrine repositories
- External APIs
- Security voters
- Middleware adapters

This is where infrastructure complexity lives, isolated from domain logic.

---

### `src/DTO/` and `Validator/`
Used for:
- JSON request bodies
- Response shaping
- Validation rules

Enables future features:
- Auto-hydration
- Strong typing
- Input validation pipeline

---

### `src/Application.php` (Optional but Recommended)

```php
<?php
declare(strict_types=1);

namespace App;

use Winglet\Core\Kernel\Bootstrap\Application as CoreApplication;

final class Application
{
    public static function boot(CoreApplication $core): void
    {
        // Register routes
        // Register services in DI container (future)
        // Hook into framework lifecycle
    }
}
```

Provides a clean extension point for app-level initialization.

Minimal HTTP Entrypoint

public/index.php
```
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Winglet\Core\Kernel\HttpKernel;
use Winglet\Core\Kernel\Bootstrap\Bootstrapper;
use Winglet\Core\Http\Request;

$kernel = new HttpKernel(
    new Bootstrapper(dirname(__DIR__))
);

$response = $kernel->handle(
    Request::fromGlobals()
);

$response->send();

Composer Configuration (App)
{
  "require": {
    "php": "^8.5",
    "winglet/core": "^1.0"
  },
  "autoload": {
    "psr-4": {
      "App\\": "src/"
    }
  }
}
```
Mental Model

Winglet\Core → how requests are handled

Application → what the system does

Domain → business truth

Infrastructure → technical implementation

This structure keeps applications:

Maintainable

Testable

Framework-upgrade-safe

Ready for growth (multi-app, mono-repo, microservices)

Next steps can include:

Wiring routes.php into the Router

DTO auto-hydration from JSON

Container-based controller instantiation

Multi-app mono-repo layout

Versioned API structure