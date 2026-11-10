<?php
declare(strict_types=1);

namespace Winglet\Core\Support;

use Winglet\Core\Exceptions\ConfigurationException;

/**
 * Configuration repository and loader.
 *
 * Supports:
 * - Loading config from directory of *.php files (each returns array).
 * - Loading defaults + environment overrides with deterministic precedence.
 * - Dot notation read access.
 *
 * Precedence when merging:
 * - Later-loaded sources override earlier ones (array_replace_recursive).
 */
final class Config
{
    /**
     * @param array<string,mixed> $data
     */
    private function __construct(private array $data) {}

    /**
     * Load a single PHP config file that returns an array.
     */
    public static function fromPhpFile(string $path): self
    {
        if (!is_file($path)) {
            return new self([]);
        }

        $data = require $path;
        if (!is_array($data)) {
            throw new ConfigurationException("Config file must return array: {$path}");
        }

        return new self($data);
    }

    /**
     * Load all *.php config files from a directory and merge them.
     *
     * Files are loaded in alphabetical order for deterministic results.
     * Each file must return an array.
     */
    public static function fromDirectory(string $dir): self
    {
        if (!is_dir($dir)) {
            return new self([]);
        }

        $files = glob(rtrim($dir, '/') . '/*.php') ?: [];
        sort($files, SORT_STRING);

        $config = [];
        foreach ($files as $file) {
            $data = require $file;

            if (!is_array($data)) {
                throw new ConfigurationException("Config file must return array: {$file}");
            }

            // Later files override earlier ones
            $config = array_replace_recursive($config, $data);
        }

        return new self($config);
    }

    /**
     * Load defaults and optional overrides, with overrides taking precedence.
     *
     * Legacy helper for two-layer configuration loading.
     *
     * New applications should prefer loadStack() with:
     * - framework defaults
     * - App/resources/config
     * - runtime /config
     */
    public static function load(string $defaultsDir, ?string $overridesDir = null): self
    {
        $defaults = self::normalizeHttpAliases(self::fromDirectory($defaultsDir)->all());
        $overrides = $overridesDir ? self::normalizeHttpAliases(self::fromDirectory($overridesDir)->all()) : [];

        // overrides win
        return new self(array_replace_recursive($defaults, $overrides));
    }

    /**
     * Load the standard three-layer Winglet configuration stack.
     *
     * Precedence, lowest to highest:
     * 1. framework defaults
     * 2. application defaults
     * 3. instance overrides
     */
    public static function loadStack(?string $frameworkDefaultsDir, ?string $appDefaultsDir, ?string $instanceOverridesDir): self
    {
        $framework = $frameworkDefaultsDir ? self::normalizeHttpAliases(self::fromDirectory($frameworkDefaultsDir)->all()) : [];
        $app       = $appDefaultsDir ? self::normalizeHttpAliases(self::fromDirectory($appDefaultsDir)->all()) : [];
        $instance  = $instanceOverridesDir ? self::normalizeHttpAliases(self::fromDirectory($instanceOverridesDir)->all()) : [];

        // precedence: instance overrides > app defaults > framework defaults
        return new self(array_replace_recursive($framework, $app, $instance));
    }

    /**
     * Dot-notation read access: e.g. doctrine.connection.host
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = $this->data;

        foreach ($segments as $seg) {
            if (!is_array($value) || !array_key_exists($seg, $value)) {
                return $default;
            }
            $value = $value[$seg];
        }

        return $value;
    }

    public function has(string $key): bool
    {
        return self::pathExists($this->data, $key);
    }

    /**
     * Read the first configured key from a primary key and one or more compatibility aliases.
     *
     * @param list<string> $keys
     */
    public function getFirst(array $keys, mixed $default = null): mixed
    {
        foreach ($keys as $key) {
            if ($this->has($key)) {
                return $this->get($key);
            }
        }

        return $default;
    }

    /**
     * @return array<string,mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * Move legacy HTTP-pipeline settings to http.* within a single configuration layer.
     *
     * This is intentionally applied before framework/app/instance layers are merged, so an
     * old application override such as session.header still overrides the new framework
     * default http.session.header.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function normalizeHttpAliases(array $data): array
    {
        self::copyAlias($data, 'logging.access.level', 'http.access_log.level');
        self::copyAlias($data, 'session.enabled', 'http.session.enabled');
        self::copyAlias($data, 'session.header', 'http.session.header');
        self::copyAlias($data, 'session.storage', 'http.session.storage');
        self::copyAlias($data, 'session.ttl_seconds', 'http.session.lifetime_seconds');
        self::copyAlias($data, 'security.session.header', 'http.session.header');
        self::copyAlias($data, 'http.trust_forwarded_prefix', 'http.trusted_proxies.trust_forwarded_prefix');

        return $data;
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function copyAlias(array &$data, string $legacyKey, string $newKey): void
    {
        if (!self::pathExists($data, $legacyKey) || self::pathExists($data, $newKey)) {
            return;
        }

        self::setPath($data, $newKey, self::readPath($data, $legacyKey));
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function pathExists(array $data, string $key): bool
    {
        $segments = explode('.', $key);
        $value = $data;

        foreach ($segments as $seg) {
            if (!is_array($value) || !array_key_exists($seg, $value)) {
                return false;
            }
            $value = $value[$seg];
        }

        return true;
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function readPath(array $data, string $key): mixed
    {
        $segments = explode('.', $key);
        $value = $data;

        foreach ($segments as $seg) {
            if (!is_array($value) || !array_key_exists($seg, $value)) {
                return null;
            }
            $value = $value[$seg];
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function setPath(array &$data, string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $cursor =& $data;

        foreach ($segments as $seg) {
            if (!isset($cursor[$seg]) || !is_array($cursor[$seg])) {
                $cursor[$seg] = [];
            }
            $cursor =& $cursor[$seg];
        }

        $cursor = $value;
    }

}