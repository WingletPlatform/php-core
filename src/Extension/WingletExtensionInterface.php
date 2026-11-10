<?php
declare(strict_types=1);

namespace Winglet\Core\Extension;

use Winglet\Core\Cli\CommandInterface;
use Winglet\Core\Container\ServiceContainer;
use Winglet\Core\Support\Config;

interface WingletExtensionInterface
{
    public function register(ServiceContainer $container, Config $config): void;

    /**
     * @return list<class-string<CommandInterface>|CommandInterface>
     */
    public function commands(Config $config): array;

    /**
     * @return list<string>
     */
    public function doctrineMappingPaths(Config $config): array;
}
