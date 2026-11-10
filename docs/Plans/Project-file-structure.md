```md
# PHP 8.5 + Doctrine Framework — Suggested Project Structure

This structure is designed for a **PHP 8.5**, **Doctrine-based** framework distributed as a **Composer package**, versioned with **Git (Bitbucket)** and built via **Bitbucket Pipelines**.  
It emphasizes clean architecture, clear public vs internal APIs, and long-term maintainability.

---

## Recommended Folder Structure


your-framework/
├─ .gitattributes
├─ .gitignore
├─ bitbucket-pipelines.yml
├─ composer.json
├─ composer.lock                # optional for libraries
├─ phpunit.xml.dist
├─ phpstan.neon.dist             # or psalm.xml
├─ phpcs.xml.dist
├─ rector.php                    # optional
├─ README.md
├─ LICENSE
├─ CHANGELOG.md
├─ SECURITY.md
├─ docs/
│  ├─ getting-started.md
│  ├─ configuration.md
│  ├─ doctrine.md
│  ├─ access-control.md
│  ├─ request-lifecycle.md
│  └─ exceptions.md
├─ resources/
│  ├─ stubs/                     # generator templates
│  └─ config/                    # example configs
├─ bin/
│  └─ framework                  # optional CLI entry
├─ src/
│  ├─ Framework.php              # optional facade/bootstrap
│  ├─ Kernel/
│  │  ├─ HttpKernel.php           # request → response pipeline
│  │  ├─ Container.php            # DI container or adapter
│  │  ├─ Pipeline/                # middleware pipeline
│  │  └─ Bootstrap/               # boot steps (env, config, doctrine)
│  ├─ Http/
│  │  ├─ Request.php
│  │  ├─ Response.php
│  │  ├─ Middleware/
│  │  ├─ Routing/
│  │  │  ├─ Router.php
│  │  │  ├─ Route.php
│  │  │  └─ Attributes/
│  │  ├─ Controller/
│  │  │  ├─ ControllerInterface.php
│  │  │  ├─ AbstractController.php
│  │  │  └─ ControllerInvoker.php
│  │  └─ Exception/
│  │     ├─ HttpException.php
│  │     ├─ NotFoundException.php
│  │     └─ MethodNotAllowedException.php
│  ├─ Security/
│  │  ├─ AccessControl/
│  │  │  ├─ AccessDecisionManager.php
│  │  │  ├─ VoterInterface.php
│  │  │  ├─ RoleHierarchy.php
│  │  │  └─ Attributes/
│  │  ├─ Auth/
│  │  │  ├─ AuthenticatorInterface.php
│  │  │  ├─ Token/
│  │  │  └─ User/
│  │  └─ Exception/
│  │     ├─ AccessDeniedException.php
│  │     └─ AuthenticationException.php
│  ├─ Doctrine/
│  │  ├─ DoctrineManager.php
│  │  ├─ EntityManagerFactory.php
│  │  ├─ Migrations/
│  │  ├─ Repository/
│  │  │  ├─ RepositoryInterface.php
│  │  │  └─ AbstractRepository.php
│  │  ├─ Types/
│  │  ├─ Events/
│  │  └─ Helper/
│  │     ├─ Transaction.php
│  │     └─ Pagination.php
│  ├─ Logging/
│  │  ├─ LoggerFactory.php
│  │  ├─ LogHelper.php
│  │  └─ NullLogger.php
│  ├─ Support/
│  │  ├─ Arr.php
│  │  ├─ Str.php
│  │  ├─ Env.php
│  │  ├─ Config.php
│  │  └─ Clock.php
│  ├─ Contracts/
│  │  ├─ Http/
│  │  ├─ Security/
│  │  ├─ Logging/
│  │  ├─ Doctrine/
│  │  └─ Kernel/
│  ├─ Exceptions/
│  │  ├─ FrameworkException.php
│  │  ├─ ConfigurationException.php
│  │  ├─ ContainerException.php
│  │  ├─ InvalidArgumentException.php
│  │  └─ RuntimeException.php
│  ├─ Traits/
│  │  ├─ Throws.php
│  │  ├─ HasContainer.php
│  │  ├─ HasLogger.php
│  │  └─ JsonResponses.php
│  └─ Internal/
│     ├─ AttributeReader.php
│     └─ Reflection/
├─ tests/
│  ├─ Unit/
│  ├─ Integration/
│  ├─ Fixtures/
│  └─ bootstrap.php
└─ examples/
├─ minimal-app/
└─ doctrine-config/

````

---

## Architectural Rationale

### Public vs Internal API
- **`Contracts/`**  
  Stable interfaces meant for framework consumers.
- **`Internal/`**  
  Non-public glue code. Can change without breaking BC.

### Request Lifecycle
- **`Kernel/`** handles bootstrapping and orchestration
- **`Http/`** contains request, response, routing, controllers, middleware

### Doctrine Integration
- Dedicated **`Doctrine/`** module
- Centralized `EntityManagerFactory`
- Helpers for transactions, pagination, custom types, and events

### Security
- Attribute-based access control
- Voter pattern
- Clear separation of auth vs authorization

### Exceptions
- Framework-wide base exceptions
- HTTP-specific exceptions separated for mapping to responses

### Utilities
- **`Support/`** for *minimal*, well-curated helpers only  
  (avoid turning it into a dumping ground)

---

## Composer Autoloading

```json
{
  "autoload": {
    "psr-4": {
      "Winglet\\Core\\": "src/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "Winglet\\Core\\Tests\\": "tests/"
    }
  }
}
````

Consider using `@internal` PHPDoc annotations for non-public classes.

---

## CI/CD Tip (Bitbucket Pipelines)

Reusable scripts:

```
tools/
  ci/
    phpunit.sh
    phpstan.sh
    phpcs.sh
```

Pipeline calls scripts instead of embedding logic in YAML.

---

## Optional Future-Proofing

If you expect growth or later extraction into components:

```
src/
  Http/
  Security/
  Doctrine/
  Logging/
  Kernel/
  Contracts/
```

Each component may include its own `Exception/` and `Internal/`.

---

This structure gives you:

* Clean boundaries
* Doctrine-first ergonomics
* Strong BC control
* Easy evolution into standalone components

If you want, this can be refined further with:

* semantic versioning + BC policy
* default configuration conventions
* request lifecycle diagrams
* framework CLI layout

```
```
