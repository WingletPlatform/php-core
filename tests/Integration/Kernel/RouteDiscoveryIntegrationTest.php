<?php
declare(strict_types=1);

namespace Winglet\Core\Tests\Integration\Kernel;

use Winglet\Core\Http\Routing\ControllerMapBuilder;
use Winglet\Core\Http\Routing\Router;
use Winglet\Core\Support\Config;
use Winglet\Core\Tests\Fixtures\RouteDiscovery\FixtureController;
use Winglet\Core\Tests\TestCase;

final class RouteDiscoveryIntegrationTest extends TestCase
{
    private ?string $temporaryDirectory = null;

    protected function tearDown(): void
    {
        if ($this->temporaryDirectory !== null && is_dir($this->temporaryDirectory)) {
            foreach (glob($this->temporaryDirectory . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->temporaryDirectory);
        }

        parent::tearDown();
    }

    public function testControllerAttributeIsDiscoveredBuiltAndMatched(): void
    {
        $directory = sys_get_temp_dir() . '/winglet_routes_' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0775, true));
        $this->temporaryDirectory = $directory;

        $configFile = $directory . '/config.php';
        file_put_contents($configFile, '<?php return ' . var_export([
            'controllers' => [
                'app' => [
                    'namespace' => 'Winglet\\Core\\Tests\\Fixtures\\RouteDiscovery\\',
                    'baseDir' => dirname(__DIR__, 2) . '/Fixtures',
                    'subDir' => 'RouteDiscovery',
                ],
                'framework' => ['list' => []],
            ],
        ], true) . ';');

        $controllersFile = $directory . '/controllers.php';
        $routesFile = $directory . '/routes.php';
        $classes = (new ControllerMapBuilder())->buildAndWrite(
            Config::fromPhpFile($configFile),
            dirname(__DIR__, 3),
            $controllersFile,
            $routesFile,
        );

        self::assertContains(FixtureController::class, $classes);
        self::assertFileExists($controllersFile);
        self::assertFileExists($routesFile);

        $router = new Router($routesFile, false);

        $static = $router->match($this->makeRequest('GET', '/fixture/static'));
        self::assertNotNull($static);
        self::assertSame(FixtureController::class, $static->controller);
        self::assertSame('staticRoute', $static->action);
        self::assertSame([], $static->params);

        $dynamic = $router->match($this->makeRequest('GET', '/fixture/items/abc-123'));
        self::assertNotNull($dynamic);
        self::assertSame(FixtureController::class, $dynamic->controller);
        self::assertSame('dynamicRoute', $dynamic->action);
        self::assertSame(['itemId' => 'abc-123'], $dynamic->params);

        self::assertNull($router->match($this->makeRequest('POST', '/fixture/static')));
    }
}
