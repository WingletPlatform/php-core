<?php

namespace Winglet\Core\Cli\Command;

use Winglet\Core\Cli\AbstractCommand;
use Winglet\Core\Http\Routing\ControllerMapBuilder;
use Winglet\Core\Kernel\Bootstrap\Application;

final class BuildRoutesCommand extends AbstractCommand
{
    public function getName(): string
    {
        return 'routes:build';
    }

    public function getDescription(): string
    {
        return 'Build controller and route cache files';
    }

    public function getHelp(): string
    {
        return 'Usage: winglet routes:build';
    }

    public function run(array $args, Application $app): int
    {
        $runtimeRoot = rtrim($app->basePath, '/\\');
        $appRoot = $runtimeRoot . '/App';

        $controllersFile = $runtimeRoot . '/var/controllers.php';
        $routesFile = $runtimeRoot . '/var/routes.php';

        if (!is_dir($runtimeRoot . '/var')) {
            mkdir($runtimeRoot . '/var', 0775, true);
        }

        $builder = new ControllerMapBuilder();
        $classes = $builder->buildAndWrite(
            $app->config,
            $appRoot,
            $controllersFile,
            $routesFile
        );

        echo "Runtime root: {$runtimeRoot}\n";
        echo "App source root: {$appRoot}\n";
        echo "Controllers generated: {$controllersFile} (" . count($classes) . ")\n";
        echo "Routes generated: {$routesFile}\n";

        return 0;
    }
}