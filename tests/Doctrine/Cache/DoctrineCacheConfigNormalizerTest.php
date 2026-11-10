<?php
declare(strict_types=1);

namespace Winglet\Core\Tests\Doctrine\Cache;

use Winglet\Core\Doctrine\Cache\DoctrineCacheConfigNormalizer;
use Winglet\Core\Exceptions\ConfigurationException;
use Winglet\Core\Support\Config;
use Winglet\Core\Tests\TestCase;

final class DoctrineCacheConfigNormalizerTest extends TestCase
{
    public function testNormalizesLegacyShortForm(): void
    {
        $config = $this->makeConfig([
            'doctrine' => [
                'cache' => [
                    'metadata' => 'array',
                ],
            ],
        ]);

        $normalized = DoctrineCacheConfigNormalizer::normalize($config, 'metadata');

        $this->assertSame('array', $normalized['driver']);
        $this->assertNull($normalized['directory']);
        $this->assertSame(0, $normalized['default_ttl']);
    }

    public function testNormalizesCanonicalFilesystemForm(): void
    {
        $config = $this->makeConfig([
            'doctrine' => [
                'cache' => [
                    'query' => [
                        'driver' => 'filesystem',
                        'directory' => 'var/custom/doctrine',
                        'default_ttl' => '60',
                    ],
                ],
            ],
        ]);

        $normalized = DoctrineCacheConfigNormalizer::normalize($config, 'query');

        $this->assertSame('filesystem', $normalized['driver']);
        $this->assertSame('var/custom/doctrine', $normalized['directory']);
        $this->assertSame(60, $normalized['default_ttl']);
    }

    public function testRejectsUnknownDriver(): void
    {
        $config = $this->makeConfig([
            'doctrine' => [
                'cache' => [
                    'result' => 'redis',
                ],
            ],
        ]);

        $this->expectException(ConfigurationException::class);
        DoctrineCacheConfigNormalizer::normalize($config, 'result');
    }

    public function testRejectsFilesystemCacheWithoutDirectory(): void
    {
        $config = $this->makeConfig([
            'doctrine' => [
                'cache' => [
                    'metadata' => [
                        'driver' => 'filesystem',
                        'directory' => '',
                    ],
                ],
            ],
        ]);

        $this->expectException(ConfigurationException::class);
        DoctrineCacheConfigNormalizer::normalize($config, 'metadata');
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
}
