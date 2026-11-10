<?php
declare(strict_types=1);

namespace Winglet\Core\Doctrine\Cache;

use Psr\Cache\CacheItemPoolInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\Psr16Adapter;
use Winglet\Core\Exceptions\ConfigurationException;
use Winglet\Core\Support\Config;

/**
 * Creates Doctrine ORM caches (metadata/query/result) from framework config.
 *
 * Doctrine expects PSR-6 caches. The framework supports both the canonical
 * nested configuration format and a legacy short form such as:
 *
 * - doctrine.cache.metadata = 'array'
 * - doctrine.cache.query = 'filesystem'
 */
final class DoctrineCacheFactory
{
    public static function createPsr6Cache(
        Config $config,
        string $basePath,
        string $purpose
    ): CacheItemPoolInterface {
        $cacheConfig = DoctrineCacheConfigNormalizer::normalize($config, $purpose);

        // Namespaces keep caches distinct even if the same directory is used.
        $namespace = 'winglet_doctrine_' . $purpose;

        return match ($cacheConfig['driver']) {
            'filesystem' => self::filesystemCache(
                (string)$cacheConfig['directory'],
                $basePath,
                (int)$cacheConfig['default_ttl'],
                $namespace,
                $purpose
            ),
            'array' => new ArrayAdapter(defaultLifetime: (int)$cacheConfig['default_ttl']),
            'none' => new ArrayAdapter(storeSerialized: false),
            default => throw new ConfigurationException(sprintf(
                'Unsupported Doctrine cache driver "%s" for "%s".',
                $cacheConfig['driver'],
                $purpose
            )),
        };
    }

    /**
     * Optional bridge for PSR-16 caches when framework services later expose one.
     */
    public static function psr16ToPsr6(CacheInterface $psr16): CacheItemPoolInterface
    {
        return new Psr16Adapter($psr16);
    }

    private static function filesystemCache(
        string $directory,
        string $basePath,
        int $ttl,
        string $namespace,
        string $purpose
    ): CacheItemPoolInterface {
        $absoluteDir = self::toAbsolutePath($directory, $basePath);

        if (!is_dir($absoluteDir) && !@mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
            throw new ConfigurationException(sprintf(
                'Unable to create Doctrine %s cache directory: %s',
                $purpose,
                $absoluteDir
            ));
        }

        return new FilesystemAdapter(
            namespace: $namespace,
            defaultLifetime: $ttl,
            directory: $absoluteDir,
        );
    }

    private static function toAbsolutePath(string $path, string $basePath): string
    {
        if (self::isAbsolutePath($path)) {
            return $path;
        }

        return rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }

    private static function isAbsolutePath(string $path): bool
    {
        if (str_starts_with($path, '/')) {
            return true;
        }

        return preg_match('#^[A-Z]:\\\\#i', $path) === 1;
    }
}
