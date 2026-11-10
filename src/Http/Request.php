<?php
declare(strict_types=1);

namespace Winglet\Core\Http;

final class Request
{
    private function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly string $originalPath,
        public readonly string $basePath,
        public readonly string $basePathSource,
        public readonly array $headers,
        public readonly array $query,
        public readonly array $post,
        public readonly ?array $json,
        public readonly string $rawBody,
        public readonly array $cookies,
        public readonly array $files,
        public readonly array $server,
        private array $attributes = [],
    ) {}

    public static function fromGlobals(string $configuredBasePath = '', bool $trustForwardedPrefix = true): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $originalPath = parse_url($uri, PHP_URL_PATH) ?: '/';

        $headers = self::collectHeaders();
        $query = $_GET;
        $post = $_POST;
        $cookies = $_COOKIE;
        $files = $_FILES;
        $server = $_SERVER;

        $rawBody = file_get_contents('php://input') ?: '';
        $json = null;

        $contentType = strtolower($headers['Content-Type'] ?? $headers['content-type'] ?? '');
        if (str_contains($contentType, 'application/json') && trim($rawBody) !== '') {
            try {
                $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $je) {
                throw new \Winglet\Core\Http\Exception\HttpException(
                    400,
                    'Invalid JSON request body',
                    [
                        'json_error' => $je->getMessage(),
                        'body_preview' => mb_substr($rawBody, 0, 2048),
                        'body_length' => strlen($rawBody),
                    ],
                    $je
                );
            }

            if (is_array($decoded)) {
                $json = $decoded;
            } else {
                throw new \Winglet\Core\Http\Exception\HttpException(
                    400,
                    'Invalid JSON request body',
                    [
                        'json_type' => gettype($decoded),
                        'body_preview' => mb_substr($rawBody, 0, 2048),
                        'body_length' => strlen($rawBody),
                    ]
                );
            }
        }

        [$basePath, $basePathSource] = self::detectBasePath($configuredBasePath, $trustForwardedPrefix, $headers);
        $path = self::stripBasePath($originalPath, $basePath);

        return new self(
            method: $method,
            path: $path,
            originalPath: $originalPath,
            basePath: $basePath,
            basePathSource: $basePathSource,
            headers: $headers,
            query: $query,
            post: $post,
            json: $json,
            rawBody: $rawBody,
            cookies: $cookies,
            files: $files,
            server: $server,
        );
    }

    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function withAttribute(string $key, mixed $value): self
    {
        $clone = clone $this;
        $clone->attributes[$key] = $value;
        return $clone;
    }

    /**
     * Return a copy of the request with a replaced query parameter array.
     *
     * Request properties are readonly by design, so callers that need a
     * normalized query for internal delegation must use a copied request
     * instead of mutating $request->query directly.
     *
     * @param array<string,mixed> $query
     */
    public function withQuery(array $query): self
    {
        return new self(
            method: $this->method,
            path: $this->path,
            originalPath: $this->originalPath,
            basePath: $this->basePath,
            basePathSource: $this->basePathSource,
            headers: $this->headers,
            query: $query,
            post: $this->post,
            json: $this->json,
            rawBody: $this->rawBody,
            cookies: $this->cookies,
            files: $this->files,
            server: $this->server,
            attributes: $this->attributes,
        );
    }

    /** @return array<string,string> */
    private static function collectHeaders(): array
    {
        $headers = [];

        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (is_string($name) && is_scalar($value)) {
                    $headers[$name] = (string)$value;
                }
            }
        }

        foreach ($_SERVER as $name => $value) {
            if (!is_string($name) || !is_scalar($value)) {
                continue;
            }

            $headerName = match (true) {
                str_starts_with($name, 'HTTP_') => self::serverHeaderName(substr($name, 5)),
                $name === 'CONTENT_TYPE' => 'Content-Type',
                $name === 'CONTENT_LENGTH' => 'Content-Length',
                default => null,
            };

            if ($headerName === null || self::hasHeader($headers, $headerName)) {
                continue;
            }

            $headers[$headerName] = (string)$value;
        }

        return $headers;
    }

    private static function serverHeaderName(string $name): string
    {
        return implode('-', array_map(
            static fn(string $part): string => ucfirst(strtolower($part)),
            explode('_', $name)
        ));
    }

    /** @param array<string,string> $headers */
    private static function hasHeader(array $headers, string $name): bool
    {
        foreach (array_keys($headers) as $existing) {
            if (strcasecmp($existing, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function detectBasePath(string $configured, bool $trustForwardedPrefix, array $headers): array
    {
        $configured = self::normalizePathPrefix($configured);
        if ($configured !== '') {
            return [$configured, 'config'];
        }

        if ($trustForwardedPrefix) {
            $xfp = $headers['X-Forwarded-Prefix'] ?? $headers['x-forwarded-prefix'] ?? null;
            if (is_string($xfp) && $xfp !== '') {
                return [self::normalizePathPrefix($xfp), 'forwarded'];
            }
        }

        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        if (is_string($script) && $script !== '') {
            $dir = str_replace('\\', '/', dirname($script));
            if ($dir !== '.' && $dir !== '/' && $dir !== '\\') {
                return [self::normalizePathPrefix($dir), 'script'];
            }
        }

        return ['', 'none'];
    }

    private static function stripBasePath(string $path, string $basePath): string
    {
        $path = self::normalizePath($path);
        $basePath = self::normalizePathPrefix($basePath);

        if ($basePath === '') {
            return $path;
        }

        if ($path === $basePath) {
            return '/';
        }

        if (str_starts_with($path, $basePath . '/')) {
            $stripped = substr($path, strlen($basePath));
            return $stripped;
        }

        return $path;
    }

    private static function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if ($path === '') return '/';
        if ($path[0] !== '/') $path = '/' . $path;
        if (strlen($path) > 1) $path = rtrim($path, '/');
        return $path;
    }

    private static function normalizePathPrefix(string $prefix): string
    {
        $prefix = self::normalizePath($prefix);
        return $prefix === '/' ? '' : $prefix;
    }
}
