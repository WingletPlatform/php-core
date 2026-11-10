<?php
declare(strict_types=1);

namespace Winglet\Core\Tests\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Monolog\Logger;
use Winglet\Core\Doctrine\EntityManagerFactory;
use Winglet\Core\Exceptions\ConfigurationException;
use Winglet\Core\Support\Config;
use Winglet\Core\Tests\TestCase;

final class EntityManagerFactoryTest extends TestCase
{
    public function testCreatesEntityManagerWithCanonicalCacheConfiguration(): void
    {
        $basePath = sys_get_temp_dir() . '/winglet_emf_' . bin2hex(random_bytes(4));
        @mkdir($basePath . '/src/Entities', 0775, true);

        $config = $this->makeConfig([
            'database' => [
                'connection' => [
                    'driver' => 'pdo_sqlite',
                    'memory' => true,
                    'dbname' => ':memory:',
                    'user' => 'test',
                    'password' => '',
                ],
            ],
            'doctrine' => [
                'dev_mode' => true,
                'mapping' => [
                    'paths' => ['src/Entities'],
                ],
                'proxy_dir' => 'var/doctrine/proxies',
                'cache' => [
                    'metadata' => [
                        'driver' => 'array',
                    ],
                    'query' => [
                        'driver' => 'array',
                    ],
                    'result' => [
                        'driver' => 'array',
                    ],
                ],
            ],
        ]);

        $em = EntityManagerFactory::create($config, new Logger('test'), $basePath);

        $this->assertInstanceOf(EntityManagerInterface::class, $em);
        $this->assertDirectoryExists($basePath . '/var/doctrine/proxies');

        $this->cleanupDirectory($basePath);
    }

    public function testCreatesEntityManagerWithLegacyShortCacheConfiguration(): void
    {
        $basePath = sys_get_temp_dir() . '/winglet_emf_' . bin2hex(random_bytes(4));
        @mkdir($basePath . '/src/Entities', 0775, true);

        $config = $this->makeConfig([
            'database' => [
                'connection' => [
                    'driver' => 'pdo_sqlite',
                    'memory' => true,
                    'dbname' => ':memory:',
                    'user' => 'test',
                    'password' => '',
                ],
            ],
            'doctrine' => [
                'dev_mode' => true,
                'mapping' => [
                    'paths' => ['src/Entities'],
                ],
                'proxy_dir' => 'var/doctrine/proxies',
                'cache' => [
                    'metadata' => 'array',
                    'query' => 'array',
                    'result' => 'array',
                ],
            ],
        ]);

        $em = EntityManagerFactory::create($config, new Logger('test'), $basePath);

        $this->assertInstanceOf(EntityManagerInterface::class, $em);
        $this->assertDirectoryExists($basePath . '/var/doctrine/proxies');

        $this->cleanupDirectory($basePath);
    }

    public function testThrowsForInvalidCacheConfiguration(): void
    {
        $basePath = sys_get_temp_dir() . '/winglet_emf_' . bin2hex(random_bytes(4));
        @mkdir($basePath . '/src/Entities', 0775, true);

        $config = $this->makeConfig([
            'database' => [
                'connection' => [
                    'driver' => 'pdo_sqlite',
                    'memory' => true,
                    'dbname' => ':memory:',
                    'user' => 'test',
                    'password' => '',
                ],
            ],
            'doctrine' => [
                'dev_mode' => true,
                'mapping' => [
                    'paths' => ['src/Entities'],
                ],
                'cache' => [
                    'metadata' => [
                        'driver' => 'redis',
                    ],
                ],
            ],
        ]);

        $this->expectException(ConfigurationException::class);
        EntityManagerFactory::create($config, new Logger('test'), $basePath);

        $this->cleanupDirectory($basePath);
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
