# Winglet.PHP Core

Winglet.PHP Core is a Composer library for building PHP applications and HTTP services. It provides an HTTP request pipeline, attribute-based routing, dependency injection, configuration, authentication and authorization components, and Doctrine integration. Applications own their controllers, domain code, deployment configuration, and front controller.

## Core and Base

Core is the PHP framework library installed as the Composer package `winglet/core`. Winglet.PHP Base is a separate PHP/Apache runtime image that can host an application using Core. Core can also be installed as a library without using Base; the image is not a Core dependency. See [php-core](https://github.com/WingletPlatform/php-core) and [php-base](https://github.com/WingletPlatform/php-base).

## Requirements

- PHP `>=8.5 <8.6`.
- PHP extensions `openssl` and `pdo`, as declared by Core. Composer will also check the requirements of dependencies.
- Composer 2 for dependency installation and the development commands below.
- Applications using Core's default database configuration need a configured PDO MySQL connection and the matching PDO driver. This is an application runtime choice, not a library installation requirement.

The package requires Doctrine ORM, Monolog, PSR cache/container/logging interfaces, and Symfony Cache. See [`composer.json`](composer.json) for the current dependency constraints.

## Installation

`winglet/core` exists on Packagist, but the listing currently describes v0.12.2, requires PHP `^8.4`, and links to the older Bitbucket repository. It does not reflect this repository's current PHP 8.5 GitHub source. For the current `main` branch, add the GitHub VCS repository to the consuming application's `composer.json`:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/WingletPlatform/php-core"
    }
  ],
  "require": {
    "winglet/core": "dev-main"
  }
}
```

Then install dependencies in the consuming application:

```sh
composer update winglet/core
```

This adds the Core library and its dependencies. It does not scaffold or start a complete application. The consuming project still needs its `App/` source tree, configuration, writable runtime directories, route map, and an HTTP entry point. Use a tagged Core release when one is published and selected for your application; do not use the Winglet Base image tag as a Core package version.

## A controller example

Controllers can use the `Route` attribute. This excerpt uses the same public APIs and response helper exercised by the HTTP integration smoke:

```php
<?php
declare(strict_types=1);

namespace App\Controller;

use Winglet\Core\Http\Controller\AbstractController;
use Winglet\Core\Http\Response;
use Winglet\Core\Http\Routing\Attributes\Route;

final class HelloController extends AbstractController
{
    #[Route('/hello', methods: ['GET'])]
    public function show(): Response
    {
        return $this->json(['message' => 'Hello']);
    }
}
```

The application's controller namespace and source directory are configured under `controllers.app.*`; route metadata is compiled into `var/routes.php`. In the standard runtime layout, the front controller passes the runtime root to `Bootstrapper` and sends the response returned by `HttpKernel`:

```php
use Winglet\Core\Kernel\Bootstrap\Bootstrapper;
use Winglet\Core\Kernel\HttpKernel;

$response = (new HttpKernel(new Bootstrapper('/var/www')))->handleGlobals();
$response->send();
```

These snippets show Core's controller and HTTP entry-point contracts. They are not a complete application scaffold: the runtime must load the application's Composer autoloader, have the expected directory layout and configuration, and build its route map before serving requests. The CI smoke creates such a temporary application in [`scripts/http-integration-smoke.sh`](scripts/http-integration-smoke.sh).

## What Core provides

- **HTTP and routing:** request/response objects, middleware pipeline, CORS and access logging, controller invocation, route attributes, and generated route maps.
- **Application services:** a service container, controller registration, extension loading, and request/application context support.
- **Identity and access:** JWT validation modes, authentication middleware, access-control attributes and decisions, session context, and tenant resolution. Identity issuance and account management belong to an identity service.
- **Persistence:** Doctrine ORM integration plus a PDO-based database helper. Applications provide their entity mappings and database configuration.
- **Operations:** Monolog-based logging, health/readiness controllers and probes, and commands for route generation and container validation.

This list describes code present in this repository. Configuration namespaces reserved for future rate limiting or security headers do not themselves implement those features.

## Configuration and database

`Bootstrapper` loads PHP configuration files in three layers, with later layers overriding earlier ones:

1. Core defaults from the installed package's `resources/config/`.
2. Application defaults from `/var/www/App/resources/config/`.
3. Runtime overrides from `/var/www/config/`.

Files are loaded alphabetically and merged recursively. Put application defaults with the application and keep deployment-specific values in runtime overrides or environment-injected configuration. The default database config selects `pdo_mysql` and reads `DB_DRIVER`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` from `$_ENV`; supply valid values when using the database. The framework also reads `APP_ENV`, `APP_TIMEZONE`, and `APP_SECRET` in its defaults. Review [`resources/config/`](resources/config/) before deploying and provide secrets through your deployment's secret mechanism.

The current `Env::fromDotenvIfPresent()` implementation does not parse `.env` files. Make environment values visible to PHP before bootstrapping; do not rely on the presence of `.env` alone to populate `$_ENV`.

## Development and verification

From a Core checkout with dependencies installed:

```sh
composer validate --no-interaction --strict
composer install --no-interaction --prefer-dist --no-progress --no-scripts
composer check-platform-reqs
composer phpstan
composer phpunit
```

The CI workflow also runs PHP syntax checks over `src/`, `tests/`, `resources/config/`, and `bin/`. The `composer test` script runs PHPStan followed by PHPUnit.

The separate HTTP/CORS integration check is:

```sh
./scripts/http-integration-smoke.sh
```

It requires Docker and a reachable Base image; its current default is `akikettu/wingletbase:v0.4.0-RC3`, overridable with `WINGLET_BASE_IMAGE`. It starts a temporary Apache/PHP application and checks HTTP behavior and CORS. PHPUnit success does not establish that this container-based smoke passes.

## CI and releases

GitHub Actions runs on pushes to `main`, version tags matching `v*`, and pull requests. The PHP job validates and installs Composer dependencies, checks platform requirements and PHP syntax, then runs PHPStan and PHPUnit. A separate job runs the Base/Core HTTP-CORS smoke. This workflow does not publish a Core package or Docker image.

Core releases are distinct from Base image releases. See [Core releases](https://github.com/WingletPlatform/php-core/releases) for Core tags. The Base image tag `v0.4.0-RC3` identifies a runtime image, not a Core version.

## License

The Composer manifest declares the package as `proprietary`, and this checkout contains no root `LICENSE` file. Treat reuse and redistribution rights as requiring confirmation from the repository owner; this README does not infer an open-source license.

## Further reading

- [Request lifecycle](docs/Architecture/request-lifecycle.md)
- [HTTP configuration](docs/Configuration/HttpConfiguration.md)
- [CLI documentation](docs/cli.md)
- [Core HTTP integration smoke](scripts/http-integration-smoke.sh)
