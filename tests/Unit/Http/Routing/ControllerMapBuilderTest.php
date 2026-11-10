<?php
declare(strict_types=1);

namespace Winglet\Core\Tests\Unit\Http\Routing;

use PHPUnit\Framework\TestCase;
use Winglet\Core\Http\Controller\EchoController;
use Winglet\Core\Http\Controller\HealthController;
use Winglet\Core\Http\Controller\ReadinessController;
use Winglet\Core\Http\Routing\ControllerMapBuilder;
use Winglet\Core\Support\Config;

final class ControllerMapBuilderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/winglet-controller-map-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->tempDir, 0775, true));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    public function testDefaultFrameworkControllersContainHealthReadinessAndEchoRoutes(): void
    {
        $configDir = $this->tempDir . '/config';
        self::assertTrue(mkdir($configDir, 0775, true));

        file_put_contents(
            $configDir . '/framework.php',
            <<<'PHP_CONFIG'
<?php
return [
    'controllers' => [
        'app' => [
            'namespace' => 'TestFixture\\Controller\\',
            'baseDir' => __DIR__ . '/missing-src',
            'subDir' => 'Controller',
        ],
    ],
];
PHP_CONFIG
        );

        $controllersFile = $this->tempDir . '/controllers.php';
        $routesFile = $this->tempDir . '/routes.php';

        $classes = (new ControllerMapBuilder())->buildAndWrite(
            Config::fromDirectory($configDir),
            $this->tempDir,
            $controllersFile,
            $routesFile,
        );

        self::assertContains(HealthController::class, $classes);
        self::assertContains(ReadinessController::class, $classes);
        self::assertContains(EchoController::class, $classes);

        /** @var array{static: array<string, array<string, array<string, mixed>>>, dynamic: array<string, mixed>} $routes */
        $routes = require $routesFile;

        self::assertSame(HealthController::class, $routes['static']['GET']['/health']['controller'] ?? null);
        self::assertSame(ReadinessController::class, $routes['static']['GET']['/readiness']['controller'] ?? null);
        self::assertSame(EchoController::class, $routes['static']['GET']['/echo']['controller'] ?? null);
        self::assertSame(EchoController::class, $routes['static']['POST']['/echo']['controller'] ?? null);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($directory);
    }
}
