<?php
declare(strict_types=1);

namespace Winglet\Core\Http\Controller;

use Winglet\Core\Http\Request;
use Winglet\Core\Http\Routing\Attributes\Route;
use Winglet\Core\Support\DevGuard;
use Winglet\Core\Kernel\Bootstrap\Application;
use Winglet\Core\Http\Routing\RouteMapBuilder;

final class DevToolsController extends AbstractController
{
    #[Route('/__dev/routes/rebuild', methods: ['POST'])]
    /** @return array<string,mixed> */
    public function handlePostRoutesRebuild(Request $request): array
    {
        /** @var Application $app */
        $app = $request->getAttribute('app');

        DevGuard::assertDevAndSecret($request, $app->config);

        $controllersFile = $app->basePath . '/var/controllers.php';
        $routesFile = $app->basePath . '/var/routes.php';

        $controllers = is_file($controllersFile) ? (require $controllersFile) : [];
        if (!is_array($controllers)) {
            $controllers = [];
        }

        (new RouteMapBuilder())->build($controllers, $routesFile);

        return [
            'ok' => true,
            'action' => 'routes_rebuild',
            'controllers' => count($controllers),
            'routesFile' => $routesFile,
        ];
    }

    #[Route('/__dev/controllers/scan', methods: ['POST'])]
    /** @return array<string,mixed> */
    public function handlePostControllersScan(Request $request): array
    {
        /** @var Application $app */
        $app = $request->getAttribute('app');

        DevGuard::assertDevAndSecret($request, $app->config);

        $controllersFile = $app->basePath . '/var/controllers.php';
        $routesFile = $app->basePath . '/var/routes.php';

        $builder = new \Winglet\Core\Http\Routing\ControllerMapBuilder();
        $classes = $builder->buildAndWrite($app->config, $app->basePath, $controllersFile, $routesFile);

        return [
            'ok' => true,
            'action' => 'controllers_map_rebuild',
            'controllers' => count($classes),
            'controllersFile' => $controllersFile,
            'routesFile' => $routesFile,
        ];
    }
}