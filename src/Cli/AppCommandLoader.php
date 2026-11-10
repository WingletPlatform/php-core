<?php
declare(strict_types=1);

namespace Winglet\Core\Cli;

use Winglet\Core\Container\ContainerException;
use Winglet\Core\Container\ServiceContainer;

/**
 * Loads app-level CLI command registrations.
 *
 * Supported hook files:
 * - {RUNTIME_ROOT}/bootstrap/commands.php
 * - {APP_ROOT}/bootstrap/commands.php
 * - {APP_ROOT}/src/Bootstrap/commands.php
 *
 * The file may return either:
 * 1) callable(ServiceContainer $c): void
 * 2) list<class-string<CommandInterface>|CommandInterface>
 */
final class AppCommandLoader
{
    public function loadIfPresent(string $runtimeRoot, ServiceContainer $container): void
    {
        $runtimeRoot = rtrim($runtimeRoot, '/\\');
        $appRoot = $runtimeRoot . '/App';

        $candidates = [
            $runtimeRoot . '/bootstrap/commands.php',
            $appRoot . '/bootstrap/commands.php',
            $appRoot . '/src/Bootstrap/commands.php',
        ];

        foreach ($candidates as $path) {
            if (!is_file($path)) {
                continue;
            }

            $this->loadFile($path, $container);
        }
    }

    private function loadFile(string $path, ServiceContainer $container): void
    {
        $result = require $path;

        if (is_callable($result)) {
            $result($container);
            return;
        }

        if (!is_array($result)) {
            throw new ContainerException("Invalid return value from {$path}: expected callable or list");
        }

        foreach ($result as $entry) {
            if (is_string($entry)) {
                if (!is_a($entry, CommandInterface::class, true)) {
                    throw new ContainerException("CLI command class must implement " . CommandInterface::class, [
                        'path' => $path,
                        'entry' => $entry,
                    ]);
                }
                $container->tag($entry, 'cli.command');
                continue;
            }

            if ($entry instanceof CommandInterface) {
                $container->tagValue('cli.command', $entry);
                continue;
            }

            throw new ContainerException("Invalid CLI command registration entry in {$path}", [
                'path' => $path,
                'entry_type' => get_debug_type($entry),
            ]);
        }
    }
}
