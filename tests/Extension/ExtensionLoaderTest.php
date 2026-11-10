<?php
declare(strict_types=1);

namespace Winglet\Core\Tests\Extension;

use PHPUnit\Framework\TestCase;
use Winglet\Core\Cli\CommandInterface;
use Winglet\Core\Container\ContainerException;
use Winglet\Core\Container\ServiceContainer;
use Winglet\Core\Extension\ExtensionLoader;
use Winglet\Core\Extension\WingletExtensionInterface;
use Winglet\Core\Kernel\Bootstrap\Application;
use Winglet\Core\Support\Config;

final class ExtensionLoaderTest extends TestCase
{
    public function testLoadsServicesCommandsAndUniqueDoctrinePaths(): void
    {
        $container = new ServiceContainer();
        $paths = (new ExtensionLoader())->load($this->config([TestExtension::class]), $container);

        self::assertSame('registered', $container->get('test.extension.service'));
        self::assertSame([TestCommand::class], $container->getTaggedIds('cli.command'));
        self::assertSame(['/extension/entities'], $paths);
    }

    public function testRejectsAssociativeExtensionConfiguration(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('app.extensions must be a list');
        (new ExtensionLoader())->load($this->rawConfig([
            'app' => ['extensions' => ['extension' => TestExtension::class]],
        ]), new ServiceContainer());
    }

    public function testRejectsNonListConfiguration(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('app.extensions must be a list');
        (new ExtensionLoader())->load($this->rawConfig(['app' => ['extensions' => 'invalid']]), new ServiceContainer());
    }

    public function testRejectsClassThatDoesNotImplementExtensionInterface(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Extension class must implement');
        (new ExtensionLoader())->load($this->config([\stdClass::class]), new ServiceContainer());
    }

    public function testRejectsEmptyDoctrineMappingPath(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('mapping paths must be non-empty');
        (new ExtensionLoader())->load($this->config([InvalidPathExtension::class]), new ServiceContainer());
    }

    /** @param list<class-string> $extensions */
    private function config(array $extensions): Config
    {
        return $this->rawConfig(['app' => ['extensions' => $extensions]]);
    }

    /** @param array<string,mixed> $data */
    private function rawConfig(array $data): Config
    {
        $path = tempnam(sys_get_temp_dir(), 'winglet-config-');
        self::assertNotFalse($path);
        file_put_contents($path, '<?php return ' . var_export($data, true) . ';');
        $config = Config::fromPhpFile($path);
        unlink($path);
        return $config;
    }
}

final class TestExtension implements WingletExtensionInterface
{
    public function register(ServiceContainer $container, Config $config): void
    {
        $container->set('test.extension.service', 'registered');
    }

    public function commands(Config $config): array
    {
        return [TestCommand::class];
    }

    public function doctrineMappingPaths(Config $config): array
    {
        return ['/extension/entities', '/extension/entities'];
    }
}

final class InvalidPathExtension implements WingletExtensionInterface
{
    public function register(ServiceContainer $container, Config $config): void {}
    public function commands(Config $config): array { return []; }
    public function doctrineMappingPaths(Config $config): array { return ['']; }
}

final class TestCommand implements CommandInterface
{
    public function getName(): string { return 'test:extension'; }
    public function getDescription(): string { return 'Test extension command'; }
    public function getHelp(): ?string { return null; }
    public function run(array $args, Application $app): int { return 0; }
}
