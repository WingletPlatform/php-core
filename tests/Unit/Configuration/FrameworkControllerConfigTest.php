<?php
declare(strict_types=1);

namespace Winglet\Core\Tests\Unit\Configuration;

use PHPUnit\Framework\TestCase;
use Winglet\Core\Http\Controller\DevToolsController;
use Winglet\Core\Http\Controller\EchoController;
use Winglet\Core\Http\Controller\HealthController;
use Winglet\Core\Http\Controller\ReadinessController;

final class FrameworkControllerConfigTest extends TestCase
{
    public function testFrameworkControllerListContainsAllBuiltInHttpControllers(): void
    {
        $configFile = dirname(__DIR__, 3) . '/resources/config/10_framework.php';

        self::assertFileExists($configFile);

        /** @var array<string, mixed> $config */
        $config = require $configFile;

        $controllers = $config['controllers']['framework']['list'] ?? null;

        self::assertIsArray($controllers);
        self::assertContains(HealthController::class, $controllers);
        self::assertContains(ReadinessController::class, $controllers);
        self::assertContains(EchoController::class, $controllers);
        self::assertContains(DevToolsController::class, $controllers);
    }
}
