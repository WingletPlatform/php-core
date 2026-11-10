<?php
declare(strict_types=1);

namespace Winglet\Core\Tests\Doctrine\Cache;

use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Winglet\Core\Doctrine\Cache\DoctrineCacheFactory;
use Winglet\Core\Exceptions\ConfigurationException;
use Winglet\Core\Support\Config;
use Winglet\Core\Tests\TestCase;

final class DoctrineCacheFactoryTest extends TestCase
{
    public function testCreatesArrayAdapterFromLegacyShortForm(): void
    {
        $config = $this->makeConfig([
            'doctrine' => [
                'cache' => [
                    'metadata' => 'array',
                ],
            ],
        ]);

        $cache = DoctrineCacheFactory::createPsr6Cache($config, sys_get_temp_dir(), 'metadata');

        $this->assertInstanceOf(ArrayAdapter::class, $cache);
    }

    public function testCreatesFilesystemAdapterAndDirectory(): void
    {
        $basePath = sys_get_temp_dir() . '/winglet_cache_factory_' . bin2hex(random_bytes(4));
        $relativeDirectory = 'var/cache/doctrine-test';

        $config = $this->makeConfig([
            'doctrine' => [
                'cache' => [
                    'query' => [
                        'driver' => 'filesystem',
                        'directory' => $relativeDirectory,
                        'default_ttl' => 120,
                    ],
                ],
            ],
        ]);

        $cache = DoctrineCacheFactory::createPsr6Cache($config, $basePath, 'query');

        $this->assertInstanceOf(FilesystemAdapter::class, $cache);
        $this->assertDirectoryExists($basePath . '/' . $relativeDirectory);

        $this->cleanupDirectory($basePath);
    }

    public function testRejectsInvalidDriver(): void
    {
        $config = $this->makeConfig([
            'doctrine' => [
                'cache' => [
                    'result' => [
                        'driver' => 'invalid',
                    ],
                ],
            ],
        ]);

        $this->expectException(ConfigurationException::class);
        DoctrineCacheFactory::createPsr6Cache($config, sys_get_temp_dir(), 'result');
    }

    /**
     * @param array<string,mixed> $data
     */
    private function makeConfig(array $data): Config
    {
        $file = sys_get_temp_dir() . '/winglet_cfg_' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, '<?php return ' . var_export($data, true) . ';');

        try {
            return Config::fromPhpFile($file);
        } finally {
            @unlink($file);
        }
    }

    private function cleanupDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;
            if (is_dir($path)) {
                $this->cleanupDirectory($path);
                @rmdir($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }
}
