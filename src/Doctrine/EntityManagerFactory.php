<?php
declare(strict_types=1);

namespace Winglet\Core\Doctrine;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Psr\Log\LoggerInterface;
use Winglet\Core\Doctrine\Cache\DoctrineCacheFactory;
use Winglet\Core\Doctrine\Logging\SqlLoggerMiddleware;
use Winglet\Core\Exceptions\ConfigurationException;
use Winglet\Core\Support\Config;

final class EntityManagerFactory
{
    /** @param list<string> $extraMappingPaths */
    public static function create(
        Config $config,
        ?LoggerInterface $logger,
        string $basePath,
        array $extraMappingPaths = [],
    ): EntityManagerInterface {
        $entityPaths = $config->get('doctrine.mapping.paths', []);
        if (!is_array($entityPaths) || $entityPaths === []) {
            throw new ConfigurationException('Doctrine mapping paths must be configured in doctrine.mapping.paths.');
        }

        $resolvedEntityPaths = [];
        foreach ($extraMappingPaths as $path) {
            $entityPaths[] = $path;
        }

        foreach ($entityPaths as $path) {
            if (!is_string($path) || trim($path) === '') {
                throw new ConfigurationException('Each Doctrine mapping path must be a non-empty string.');
            }

            $resolvedEntityPaths[] = self::resolveCodePath($basePath, $path);
        }

        $isDevMode = (bool) $config->get('doctrine.dev_mode', false);

        $ormConfig = ORMSetup::createAttributeMetadataConfiguration(
            paths: $resolvedEntityPaths,
            isDevMode: $isDevMode,
        );

        $ormConfig->enableNativeLazyObjects(true);

        $ormConfig->setMetadataCache(DoctrineCacheFactory::createPsr6Cache($config, $basePath, 'metadata'));
        $ormConfig->setQueryCache(DoctrineCacheFactory::createPsr6Cache($config, $basePath, 'query'));
        $ormConfig->setResultCache(DoctrineCacheFactory::createPsr6Cache($config, $basePath, 'result'));

        $proxyDir = (string) $config->get('doctrine.proxy_dir', 'var/doctrine/proxies');
        $proxyDir = self::resolveRuntimePath($basePath, $proxyDir);
        self::ensureDirectoryExists($proxyDir);
        $ormConfig->setProxyDir($proxyDir);

        $autoGenerateProxies = (bool) $config->get('doctrine.auto_generate_proxies', $isDevMode);
        $ormConfig->setAutoGenerateProxyClasses($autoGenerateProxies);

        $connectionParams = $config->get('database.connection', $config->get('doctrine.connection', []));
        if (!is_array($connectionParams) || $connectionParams === []) {
            throw new ConfigurationException('Doctrine connection must be configured in database.connection or doctrine.connection.');
        }

        if ((bool) $config->get('doctrine.sql_logger.enabled', false) && $logger !== null) {
            $connectionParams['middlewares'] ??= [];
            $connectionParams['middlewares'][] = new SqlLoggerMiddleware($logger);
        }

        $connection = DriverManager::getConnection($connectionParams, $ormConfig);

        return new EntityManager($connection, $ormConfig);
    }

    private static function resolveCodePath(string $basePath, string $path): string
    {
        if (self::isAbsolutePath($path)) {
            return $path;
        }

        $runtimeRoot = rtrim($basePath, '/\\');
        $appRoot = $runtimeRoot . '/App';
        $trimmed = ltrim($path, '/\\');

        if (is_dir($appRoot)) {
            return $appRoot . '/' . $trimmed;
        }

        return $runtimeRoot . '/' . $trimmed;
    }

    private static function resolveRuntimePath(string $basePath, string $path): string
    {
        if (self::isAbsolutePath($path)) {
            return $path;
        }

        return rtrim($basePath, '/\\') . '/' . ltrim($path, '/\\');
    }

    private static function ensureDirectoryExists(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!mkdir($path, 0775, true) && !is_dir($path)) {
            throw new ConfigurationException(sprintf(
                'Failed to create Doctrine runtime directory "%s".',
                $path
            ));
        }
    }

    private static function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if ($path[0] === '/' || $path[0] === '\\') {
            return true;
        }

        return preg_match('#^[A-Z]:[\\\\/]#i', $path) === 1;
    }
}
