<?php
declare(strict_types=1);

namespace Winglet\Core\Tests\Integration\Kernel;

use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Winglet\Core\Container\ServiceContainer;
use Winglet\Core\Health\ReadinessCheckRunner;
use Winglet\Core\Health\ReadinessProbeInterface;
use Winglet\Core\Health\ReadinessProbeResult;
use Winglet\Core\Http\Exception\ApiError;
use Winglet\Core\Http\Exception\ApiExceptionMapperInterface;
use Winglet\Core\Http\Request;
use Winglet\Core\Kernel\Bootstrap\Bootstrapper;
use Winglet\Core\Kernel\Context\AppContext;
use Winglet\Core\Kernel\Context\AppContextProviderInterface;
use Winglet\Core\Tests\TestCase;

final class BootstrapperIntegrationTest extends TestCase
{
    private ?string $runtimeRoot = null;

    protected function tearDown(): void
    {
        if ($this->runtimeRoot !== null) {
            $this->removeDirectory($this->runtimeRoot);
        }

        parent::tearDown();
    }

    public function testBootLoadsConfigurationOverridesAndApplicationServices(): void
    {
        $root = $this->createFixtureApplication();
        $application = (new Bootstrapper($root))->boot();

        self::assertSame('instance-name', $application->config->get('app.name'));
        self::assertSame('from-application-services', $application->container->get('fixture.override'));
        self::assertInstanceOf(FixtureAppContextProvider::class, $application->container->get(AppContextProviderInterface::class));
        self::assertInstanceOf(FixtureApiExceptionMapper::class, $application->container->get(ApiExceptionMapperInterface::class));

        $readiness = $application->container->get(ReadinessCheckRunner::class)->run();
        self::assertTrue($readiness['ready']);
        self::assertSame(
            ['ready' => true, 'code' => null],
            $readiness['checks']['fixture.bootstrap'],
        );

        $entityManager = $application->container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertTrue($entityManager->isOpen());
    }

    private function createFixtureApplication(): string
    {
        $root = sys_get_temp_dir() . '/winglet_bootstrap_' . bin2hex(random_bytes(6));
        $this->runtimeRoot = $root;

        foreach ([
            '/App/resources/config',
            '/App/bootstrap',
            '/App/src/Entities',
            '/App/src/Controller',
            '/config',
            '/var',
        ] as $directory) {
            self::assertTrue(mkdir($root . $directory, 0775, true));
        }

        $this->writePhpConfig($root . '/App/resources/config/10_app.php', [
            'app' => ['name' => 'application-name'],
            'logging' => [
                'driver' => 'null',
                'access' => ['driver' => 'null'],
                'security' => ['driver' => 'null'],
            ],
            'database' => [
                'connection' => [
                    'driver' => 'pdo_sqlite',
                    'memory' => true,
                ],
            ],
            'doctrine' => [
                'dev_mode' => true,
                'proxy_dir' => 'var/doctrine/proxies',
                'mapping' => [
                    'paths' => ['src/Entities'],
                    'namespaces' => ['App\\Entities'],
                ],
                'cache' => [
                    'metadata' => ['driver' => 'array'],
                    'query' => ['driver' => 'array'],
                    'result' => ['driver' => 'array'],
                ],
            ],
            'authentication' => [
                'jwt' => [
                    'mode' => 'standard',
                    'standard' => [
                        'algorithms' => ['HS256'],
                        'keys' => [
                            'fixture' => ['alg' => 'HS256', 'secret' => 'fixture-secret'],
                        ],
                        'jwks' => ['enabled' => false],
                    ],
                ],
            ],
            'controllers' => [
                'app' => [
                    'namespace' => 'App\\Controller\\',
                    'baseDir' => $root . '/App/src',
                    'subDir' => 'Controller',
                ],
                'framework' => ['list' => []],
            ],
        ]);

        $this->writePhpConfig($root . '/config/99_instance.php', [
            'app' => ['name' => 'instance-name'],
        ]);

        $services = <<<'PHP'
<?php
declare(strict_types=1);

use Winglet\Core\Container\ServiceContainer;
use Winglet\Core\Health\ReadinessProbeInterface;
use Winglet\Core\Http\Exception\ApiExceptionMapperInterface;
use Winglet\Core\Kernel\Context\AppContextProviderInterface;
use Winglet\Core\Tests\Integration\Kernel\FixtureApiExceptionMapper;
use Winglet\Core\Tests\Integration\Kernel\FixtureAppContextProvider;
use Winglet\Core\Tests\Integration\Kernel\FixtureReadinessProbe;

return static function (ServiceContainer $container): void {
    $container->set('fixture.override', 'from-application-services');
    $container->set(AppContextProviderInterface::class, new FixtureAppContextProvider());
    $container->set(ApiExceptionMapperInterface::class, new FixtureApiExceptionMapper());
    $container->set(ReadinessProbeInterface::class, new FixtureReadinessProbe());
    $container->tag(ReadinessProbeInterface::class, 'readiness.probe');
};
PHP;
        file_put_contents($root . '/App/bootstrap/services.php', $services);

        return $root;
    }

    /** @param array<string,mixed> $config */
    private function writePhpConfig(string $path, array $config): void
    {
        file_put_contents($path, "<?php\ndeclare(strict_types=1);\nreturn " . var_export($config, true) . ";\n");
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
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($directory);
    }
}

final class FixtureAppContextProvider implements AppContextProviderInterface
{
    public function create(Request $request): AppContext
    {
        throw new RuntimeException('Fixture provider is not invoked during bootstrap.');
    }
}

final class FixtureApiExceptionMapper implements ApiExceptionMapperInterface
{
    public function map(\Throwable $exception, Request $request): ?ApiError
    {
        return null;
    }
}

final class FixtureReadinessProbe implements ReadinessProbeInterface
{
    public function name(): string
    {
        return 'fixture.bootstrap';
    }

    public function check(): ReadinessProbeResult
    {
        return ReadinessProbeResult::ready();
    }
}
