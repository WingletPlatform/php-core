<?php
declare(strict_types=1);

namespace Winglet\Core\Cli\Command;

use Psr\Container\ContainerInterface;
use Throwable;
use Winglet\Core\Cli\AbstractCommand;
use Winglet\Core\Container\ServiceContainer;
use Winglet\Core\Kernel\Bootstrap\Application;
use Winglet\Core\Validation\ContainerValidationContext;
use Winglet\Core\Validation\ContainerValidationProbeInterface;
use Winglet\Core\Validation\RouteMapValidator;
use Winglet\Core\Validation\RouteValidationError;
use Winglet\Core\Validation\ServiceContainerValidator;
use Winglet\Core\Validation\ServiceValidationError;

final class ValidateContainerCommand extends AbstractCommand
{
    public function getName(): string
    {
        return 'validate:container';
    }

    public function getDescription(): string
    {
        return 'Validate service container, route controllers and validation probes';
    }

    public function getHelp(): string
    {
        return <<<'TXT'
Usage: winglet validate:container [options]

Options:
  --include-controllers   Include controllers from generated route map in service validation
  --include-routes        Validate generated route map and route controller wiring
  --include-probes        Execute services tagged as validation.probe
  --fail-on-warning       Reserved for future warning categories; currently errors fail regardless

Configuration:
  validation.container.include          list of service ids/classes to instantiate
  validation.container.exclude          list of service ids/classes to skip
  validation.container.include_routes   boolean default for --include-routes
  validation.container.include_probes   boolean default for --include-probes

Tags:
  validation.service                    service ids to instantiate
  validation.probe                      ContainerValidationProbeInterface implementations to execute
TXT;
    }

    public function run(array $args, Application $app): int
    {
        $container = $app->container;
        if (!$container instanceof ServiceContainer) {
            fwrite(STDERR, "Container validation requires Winglet\\Core\\Container\\ServiceContainer.\n");
            return 1;
        }

        $includeRoutes = $this->optionEnabled($args, '--include-routes')
            || (bool)$app->config->get('validation.container.include_routes', false);
        $includeControllers = $this->optionEnabled($args, '--include-controllers')
            || (bool)$app->config->get('validation.container.include_controllers', false);
        $includeProbes = $this->optionEnabled($args, '--include-probes')
            || (bool)$app->config->get('validation.container.include_probes', false);

        $serviceIds = $this->serviceIds($app, $container);
        $routeMapFile = rtrim($app->basePath, '/\\') . '/var/routes.php';
        $routeErrors = [];

        if ($includeRoutes || $includeControllers) {
            $routeServiceIds = $this->routeControllerIds($routeMapFile);
            $serviceIds = array_merge($serviceIds, $routeServiceIds);
        }

        if ($includeRoutes) {
            $routeErrors = (new RouteMapValidator())->validateFile($routeMapFile, $container);
        }

        $serviceErrors = (new ServiceContainerValidator())->validate($container, $serviceIds);
        $probeErrors = $includeProbes ? $this->runProbes($container) : [];

        $allServiceErrors = array_merge($serviceErrors, $probeErrors);

        if ($allServiceErrors === [] && $routeErrors === []) {
            fwrite(STDOUT, "Container validation passed.\n");
            fwrite(STDOUT, sprintf("Validated services: %d\n", count(array_unique($serviceIds))));
            if ($includeRoutes) {
                fwrite(STDOUT, "Route validation: passed\n");
            }
            if ($includeProbes) {
                fwrite(STDOUT, "Validation probes: passed\n");
            }
            return 0;
        }

        fwrite(STDERR, "Container validation failed.\n\n");

        if ($allServiceErrors !== []) {
            fwrite(STDERR, "Service wiring errors:\n");
            foreach ($allServiceErrors as $error) {
                fwrite(STDERR, $this->formatServiceError($error));
            }
            fwrite(STDERR, "\n");
        }

        if ($routeErrors !== []) {
            fwrite(STDERR, "Route validation errors:\n");
            foreach ($routeErrors as $error) {
                fwrite(STDERR, $this->formatRouteError($error));
            }
        }

        return 1;
    }

    /** @param list<string> $args */
    private function optionEnabled(array $args, string $option): bool
    {
        return in_array($option, $args, true);
    }

    /** @return list<string> */
    private function serviceIds(Application $app, ServiceContainer $container): array
    {
        $ids = [];
        $configured = $app->config->get('validation.container.include', []);
        if (is_array($configured)) {
            foreach ($configured as $id) {
                if (is_string($id) && $id !== '') {
                    $ids[] = $id;
                }
            }
        }

        foreach ($container->getTaggedIds('validation.service') as $id) {
            $ids[] = $id;
        }

        $excluded = [];
        $configuredExcluded = $app->config->get('validation.container.exclude', []);
        if (is_array($configuredExcluded)) {
            foreach ($configuredExcluded as $id) {
                if (is_string($id) && $id !== '') {
                    $excluded[$id] = true;
                }
            }
        }

        return array_values(array_unique(array_filter(
            $ids,
            static fn (string $id): bool => !isset($excluded[$id])
        )));
    }

    /** @return list<string> */
    private function routeControllerIds(string $routeMapFile): array
    {
        if (!is_file($routeMapFile)) {
            return [];
        }

        $map = require $routeMapFile;
        if (!is_array($map)) {
            return [];
        }

        $controllers = [];
        $static = $map['static'] ?? [];
        if (is_array($static)) {
            foreach ($static as $paths) {
                if (!is_array($paths)) {
                    continue;
                }
                foreach ($paths as $entry) {
                    if (is_array($entry) && isset($entry['controller']) && is_string($entry['controller'])) {
                        $controllers[] = $entry['controller'];
                    }
                }
            }
        }

        $dynamic = $map['dynamic'] ?? [];
        if (is_array($dynamic)) {
            foreach ($dynamic as $entries) {
                if (!is_array($entries)) {
                    continue;
                }
                foreach ($entries as $entry) {
                    if (is_array($entry) && isset($entry['controller']) && is_string($entry['controller'])) {
                        $controllers[] = $entry['controller'];
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($controllers, static fn (string $id): bool => $id !== '')));
    }

    /** @return list<ServiceValidationError> */
    private function runProbes(ServiceContainer $container): array
    {
        $errors = [];

        foreach ($container->getTaggedIds('validation.probe') as $probeId) {
            try {
                $probe = $container->get($probeId);
                if (!$probe instanceof ContainerValidationProbeInterface) {
                    $errors[] = new ServiceValidationError(
                        serviceId: $probeId,
                        message: sprintf('Validation probe must implement %s, got %s.', ContainerValidationProbeInterface::class, get_debug_type($probe)),
                        metadata: $container->getMetadata($probeId),
                    );
                    continue;
                }

                $context = new ContainerValidationContext($container);
                try {
                    $probe->validate($context);
                } catch (Throwable $e) {
                    $context->fail($probe->name(), $e);
                }

                foreach ($context->errors() as $error) {
                    $errors[] = $error;
                }
            } catch (Throwable $e) {
                $errors[] = ServiceValidationError::fromThrowable($probeId, $e, $container->getMetadata($probeId));
            }
        }

        return $errors;
    }

    private function formatServiceError(ServiceValidationError $error): string
    {
        $lines = [];
        $lines[] = sprintf("- Service: %s", $error->serviceId);

        $source = $error->metadata['source'] ?? null;
        if (is_string($source) && $source !== '') {
            $lines[] = sprintf("  Registered in: %s", $source);
        }

        if ($error->exceptionClass !== null) {
            $lines[] = sprintf("  Exception: %s", $error->exceptionClass);
        }

        $lines[] = sprintf("  Error: %s", $error->message);

        if ($error->file !== null) {
            $location = $error->file;
            if ($error->line !== null) {
                $location .= ':' . $error->line;
            }
            $lines[] = sprintf("  Location: %s", $location);
        }

        return implode("\n", $lines) . "\n";
    }

    private function formatRouteError(RouteValidationError $error): string
    {
        $lines = [];
        $lines[] = sprintf("- Route: %s", $error->route);
        if ($error->controller !== null) {
            $lines[] = sprintf("  Controller: %s", $error->controller);
        }
        if ($error->action !== null) {
            $lines[] = sprintf("  Action: %s", $error->action);
        }
        $lines[] = sprintf("  Error: %s", $error->message);

        return implode("\n", $lines) . "\n";
    }
}
