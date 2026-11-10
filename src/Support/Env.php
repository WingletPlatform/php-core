<?php
declare(strict_types=1);

/**
 * Environment helper (dummy loader).
 *
 * Responsibilities:
 * - Loads environment variables (later: proper dotenv parsing).
 * - Provides typed access to env values via get().
 *
 * Called from:
 * - Bootstrapper when booting the framework.
 */


namespace Winglet\Core\Support;

final class Env
{
    /**
     * @param array<string,mixed> $vars
     */
    private function __construct(public readonly array $vars) {}

    public static function fromDotenvIfPresent(string $path): self
    {
        // Dummy loader. Later: parse dotenv properly or rely on vlucas/phpdotenv.
        if (is_file($path)) {
            // no-op for now
        }
        return new self($_ENV + $_SERVER);
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $v = $this->vars[$key] ?? $default;
        return is_string($v) ? $v : $default;
    }
}