<?php
declare(strict_types=1);

namespace Winglet\Core\Tests\Unit\Logging;

use Psr\Log\NullLogger;
use Winglet\Core\Logging\LoggerFactory;
use Winglet\Core\Support\Config;
use Winglet\Core\Tests\TestCase;

final class LoggerFactoryTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->paths) as $path) {
            is_dir($path) ? @rmdir($path) : @unlink($path);
        }
        parent::tearDown();
    }

    public function testNullAndUnknownDriversReturnNullLogger(): void
    {
        $factory = new LoggerFactory();
        self::assertInstanceOf(NullLogger::class, $factory->create($this->config(['driver' => 'null'])));
        self::assertInstanceOf(NullLogger::class, $factory->create($this->config(['driver' => 'unknown'])));
    }

    public function testFileDriverCreatesDirectoryAndWritesAtConfiguredLevel(): void
    {
        $directory = sys_get_temp_dir() . '/winglet_logger_' . bin2hex(random_bytes(5));
        $path = $directory . '/app.log';
        $this->paths[] = $directory;
        $this->paths[] = $path;

        $logger = (new LoggerFactory())->create($this->config([
            'driver' => 'file',
            'path' => $path,
            'channel' => 'test',
            'severity' => 'warning',
        ]));
        $logger->info('not written');
        $logger->warning('written message');

        self::assertFileExists($path);
        $contents = file_get_contents($path);
        self::assertIsString($contents);
        self::assertStringNotContainsString('not written', $contents);
        self::assertStringContainsString('written message', $contents);
        self::assertStringContainsString('test.WARNING', $contents);
    }

    /** @param array<string,mixed> $logging */
    private function config(array $logging): Config
    {
        $file = tempnam(sys_get_temp_dir(), 'winglet_logger_config_');
        self::assertIsString($file);
        $this->paths[] = $file;
        file_put_contents($file, '<?php return ' . var_export(['logging' => $logging], true) . ';');
        return Config::fromPhpFile($file);
    }
}
