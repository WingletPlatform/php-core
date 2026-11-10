<?php
declare(strict_types=1);

namespace Winglet\Core\Doctrine\Cache;

use Winglet\Core\Exceptions\ConfigurationException;
use Winglet\Core\Support\Config;

/**
 * Normalizes Doctrine cache configuration into a canonical structure.
 *
 * Supported input formats per cache purpose (metadata/query/result):
 *
 * 1) Short form:
 *    doctrine.cache.metadata = 'array'
 *
 * 2) Canonical form:
 *    doctrine.cache.metadata = [
 *        'driver' => 'filesystem',
 *        'directory' => 'var/cache/doctrine',
 *        'default_ttl' => 0,
 *    ]
 *
 * Returned shape always contains:
 * - driver: string
 * - directory: string|null
 * - default_ttl: int
 */
final class DoctrineCacheConfigNormalizer
{
    /**
     * @return array{driver:string,directory:string|null,default_ttl:int}
     */
    public static function normalize(Config $config, string $purpose): array
    {
        if (!in_array($purpose, ['metadata', 'query', 'result'], true)) {
            throw new ConfigurationException(sprintf(
                'Unsupported Doctrine cache purpose "%s". Expected one of: metadata, query, result.',
                $purpose
            ));
        }

        $raw = $config->get("doctrine.cache.$purpose", 'array');

        if (is_string($raw)) {
            return self::normalizeFromDriver($raw);
        }

        if (!is_array($raw)) {
            throw new ConfigurationException(sprintf(
                'Doctrine cache configuration for "%s" must be a string driver or an array.',
                $purpose
            ));
        }

        $driver = $raw['driver'] ?? 'array';
        if (!is_string($driver) || trim($driver) === '') {
            throw new ConfigurationException(sprintf(
                'Doctrine cache driver for "%s" must be a non-empty string.',
                $purpose
            ));
        }

        return self::normalizeFromArray($driver, $raw, $purpose);
    }

    /**
     * @return array{driver:string,directory:string|null,default_ttl:int}
     */
    private static function normalizeFromDriver(string $driver): array
    {
        $driver = strtolower(trim($driver));

        return match ($driver) {
            'filesystem' => [
                'driver' => 'filesystem',
                'directory' => 'var/cache/doctrine',
                'default_ttl' => 0,
            ],
            'array', 'none' => [
                'driver' => $driver,
                'directory' => null,
                'default_ttl' => 0,
            ],
            default => throw new ConfigurationException(sprintf(
                'Unsupported Doctrine cache driver "%s". Supported drivers: filesystem, array, none.',
                $driver
            )),
        };
    }

    /**
     * @param array<string,mixed> $raw
     * @return array{driver:string,directory:string|null,default_ttl:int}
     */
    private static function normalizeFromArray(string $driver, array $raw, string $purpose): array
    {
        $normalized = self::normalizeFromDriver($driver);

        if (array_key_exists('directory', $raw)) {
            $directory = $raw['directory'];
            if ($directory !== null && !is_string($directory)) {
                throw new ConfigurationException(sprintf(
                    'Doctrine cache directory for "%s" must be a string or null.',
                    $purpose
                ));
            }
            $normalized['directory'] = $directory;
        }

        if (array_key_exists('default_ttl', $raw)) {
            $ttl = $raw['default_ttl'];
            if (!is_int($ttl) && !(is_string($ttl) && is_numeric($ttl))) {
                throw new ConfigurationException(sprintf(
                    'Doctrine cache default_ttl for "%s" must be an integer.',
                    $purpose
                ));
            }
            $normalized['default_ttl'] = (int) $ttl;
        }

        if (
            $normalized['driver'] === 'filesystem'
            && ($normalized['directory'] === null || trim($normalized['directory']) === '')
        ) {
            throw new ConfigurationException(sprintf(
                'Doctrine filesystem cache for "%s" requires a non-empty directory.',
                $purpose
            ));
        }

        return $normalized;
    }
}