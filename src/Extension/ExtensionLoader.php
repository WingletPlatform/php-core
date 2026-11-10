<?php
declare(strict_types=1);

namespace Winglet\Core\Extension;

use Winglet\Core\Container\ContainerException;
use Winglet\Core\Container\ServiceContainer;
use Winglet\Core\Support\Config;

final class ExtensionLoader
{
    /**
     * @return list<string> Doctrine mapping paths provided by loaded extensions.
     */
    public function load(Config $config, ServiceContainer $container): array
    {
        $configured = $config->get('app.extensions', []);
        if (!is_array($configured) || !array_is_list($configured)) {
            throw new ContainerException('app.extensions must be a list of extension class names.');
        }

        $mappingPaths = [];
        foreach ($configured as $class) {
            if (!is_string($class) || trim($class) === '') {
                throw new ContainerException('Each app.extensions entry must be a non-empty class name.');
            }
            if (!is_a($class, WingletExtensionInterface::class, true)) {
                throw new ContainerException(sprintf('Extension class must implement %s.', WingletExtensionInterface::class), [
                    'extension' => $class,
                ]);
            }

            /** @var WingletExtensionInterface $extension */
            $extension = new $class();
            $extension->register($container, $config);

            foreach ($extension->commands($config) as $command) {
                if (is_string($command)) {
                    $container->tag($command, 'cli.command');
                    continue;
                }

                $container->tagValue('cli.command', $command);
            }

            foreach ($extension->doctrineMappingPaths($config) as $path) {
                if (trim($path) === '') {
                    throw new ContainerException('Extension doctrine mapping paths must be non-empty strings.', [
                        'extension' => $class,
                    ]);
                }
                $mappingPaths[] = $path;
            }
        }

        return array_values(array_unique($mappingPaths));
    }
}
