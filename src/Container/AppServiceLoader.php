<?php
declare(strict_types=1);

namespace Winglet\Core\Container;

/**
 * Loads app-level service registrations into the container.
 *
 * Supported hook files:
 * - {RUNTIME_ROOT}/bootstrap/services.php
 * - {APP_ROOT}/bootstrap/services.php
 * - {APP_ROOT}/src/Bootstrap/services.php
 *
 * The file may return either:
 * 1) callable(ServiceContainer $c): void
 * 2) array<string,mixed> definitions (values or callables/factories)
 */
final class AppServiceLoader
{
    public function loadIfPresent(string $runtimeRoot, ServiceContainer $container): void
    {
        $runtimeRoot = rtrim($runtimeRoot, '/\\');
        $appRoot = $runtimeRoot . '/App';

        $candidates = [
            $runtimeRoot . '/bootstrap/services.php',
            $appRoot . '/bootstrap/services.php',
            $appRoot . '/src/Bootstrap/services.php',
        ];

        $path = null;
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $path = $candidate;
                break;
            }
        }

        if ($path === null) {
            return;
        }

        $result = require $path;

        if (is_callable($result)) {
            $result($container);
            return;
        }

        if (is_array($result)) {
            foreach ($result as $id => $def) {
                if (!is_string($id) || $id === '') {
                    throw new ContainerException("Invalid service id in {$path}");
                }
                $container->setWithMetadata($id, $def, [
                    'source' => $path,
                    'loader' => self::class,
                    'registration' => 'array',
                ]);
            }
            return;
        }

        throw new ContainerException("Invalid return value from {$path}: expected callable or array");
    }
}
