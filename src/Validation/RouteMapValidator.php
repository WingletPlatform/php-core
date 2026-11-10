<?php
declare(strict_types=1);

namespace Winglet\Core\Validation;

use Psr\Container\ContainerInterface;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

final class RouteMapValidator
{
    /**
     * @return list<RouteValidationError>
     */
    public function validateFile(string $routeMapFile, ContainerInterface $container): array
    {
        if (!is_file($routeMapFile)) {
            return [new RouteValidationError($routeMapFile, 'Route map file does not exist.')];
        }

        $map = require $routeMapFile;
        if (!is_array($map)) {
            return [new RouteValidationError($routeMapFile, 'Route map file must return an array.')];
        }

        return $this->validateMap($map, $container);
    }

    /**
     * @param array<string,mixed> $map
     * @return list<RouteValidationError>
     */
    public function validateMap(array $map, ContainerInterface $container): array
    {
        $errors = [];

        foreach ($this->entries($map) as $entry) {
            $route = $entry['route'];
            $controller = $entry['controller'];
            $action = $entry['action'];
            $params = $entry['params'];

            if ($controller === '') {
                $errors[] = new RouteValidationError($route, 'Route entry does not define controller.');
                continue;
            }

            if ($action === '') {
                $errors[] = new RouteValidationError($route, 'Route entry does not define action.', $controller);
                continue;
            }

            if (!class_exists($controller)) {
                $errors[] = new RouteValidationError($route, 'Controller class does not exist.', $controller, $action);
                continue;
            }

            if (!method_exists($controller, $action)) {
                $errors[] = new RouteValidationError($route, 'Controller action method does not exist.', $controller, $action);
                continue;
            }

            try {
                $instance = $container->get($controller);
                if (!is_object($instance)) {
                    $errors[] = new RouteValidationError($route, sprintf('Container returned %s instead of controller object.', get_debug_type($instance)), $controller, $action);
                }
            } catch (Throwable $e) {
                $errors[] = new RouteValidationError($route, $e->getMessage(), $controller, $action);
                continue;
            }

            $errors = array_merge($errors, $this->validateRequiredRouteParameters($route, $controller, $action, $params));
        }

        return $errors;
    }

    /**
     * @param array<string,mixed> $map
     * @return list<array{route:string,controller:string,action:string,params:list<string>}>
     */
    private function entries(array $map): array
    {
        $entries = [];

        $static = $map['static'] ?? [];
        if (is_array($static)) {
            foreach ($static as $method => $paths) {
                if (!is_array($paths)) {
                    continue;
                }
                foreach ($paths as $path => $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $entries[] = [
                        'route' => strtoupper((string)$method) . ' ' . (string)$path,
                        'controller' => (string)($entry['controller'] ?? ''),
                        'action' => (string)($entry['action'] ?? ''),
                        'params' => [],
                    ];
                }
            }
        }

        $dynamic = $map['dynamic'] ?? [];
        if (is_array($dynamic)) {
            foreach ($dynamic as $method => $list) {
                if (!is_array($list)) {
                    continue;
                }
                foreach ($list as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $params = [];
                    $rawParams = $entry['params'] ?? [];
                    if (is_array($rawParams)) {
                        foreach ($rawParams as $param) {
                            if (is_string($param) && $param !== '') {
                                $params[] = $param;
                            }
                        }
                    }

                    $entries[] = [
                        'route' => strtoupper((string)$method) . ' ' . (string)($entry['path'] ?? $entry['regex'] ?? '<dynamic>'),
                        'controller' => (string)($entry['controller'] ?? ''),
                        'action' => (string)($entry['action'] ?? ''),
                        'params' => $params,
                    ];
                }
            }
        }

        return $entries;
    }

    /**
     * @param list<string> $routeParams
     * @return list<RouteValidationError>
     */
    private function validateRequiredRouteParameters(string $route, string $controller, string $action, array $routeParams): array
    {
        $errors = [];
        $method = new ReflectionMethod($controller, $action);

        foreach ($method->getParameters() as $parameter) {
            if ($parameter->isOptional() || $parameter->isDefaultValueAvailable()) {
                continue;
            }

            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                continue;
            }
            if ($type !== null && !$type instanceof ReflectionNamedType) {
                // Union/intersection typed framework parameters cannot be matched reliably here.
                // Container/controller invocation is responsible for runtime handling.
                continue;
            }

            $name = $parameter->getName();
            if (!in_array($name, $routeParams, true)) {
                $errors[] = new RouteValidationError(
                    route: $route,
                    message: sprintf("Required scalar action parameter '$%s' is not provided by route params.", $name),
                    controller: $controller,
                    action: $action,
                );
            }
        }

        return $errors;
    }
}
