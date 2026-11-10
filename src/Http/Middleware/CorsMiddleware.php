<?php
declare(strict_types=1);

namespace Winglet\Core\Http\Middleware;

use Winglet\Core\Http\Request;
use Winglet\Core\Http\Response;
use Winglet\Core\Kernel\Middleware\MiddlewareInterface;
use Winglet\Core\Support\Config;

final class CorsMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Config $config) {}

    public function process(Request $request, callable $next): Response
    {
        if (!$this->isEnabled()) {
            return $this->withoutCorsHeaders($next($request));
        }

        if ($this->isPreflightRequest($request)) {
            $response = Response::raw('', 204);
            $this->appendVary($response, 'Origin');
            $this->appendVary($response, 'Access-Control-Request-Method');
            $this->appendVary($response, 'Access-Control-Request-Headers');

            $origin = $this->allowedOrigin($request);
            $method = $this->header($request, 'Access-Control-Request-Method');
            $headers = $this->requestedHeaders($request);
            if ($origin === '' || !$this->contains($this->configList('http.cors.allow_methods', ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']), $method)) {
                $response->status = 403;
                return $response;
            }

            $allowedHeaders = $this->lowercase($this->configList('http.cors.allow_headers', ['Authorization', 'Content-Type', 'Accept', 'Origin', 'X-Requested-With', 'X-Tenant-Code', 'X-Request-Id', 'X-Correlation-Id']));
            foreach ($headers as $header) {
                if (!in_array(strtolower($header), $allowedHeaders, true)) {
                    $response->status = 403;
                    return $response;
                }
            }

            return $this->withCorsHeaders($request, $response, $origin);
        }

        $response = $next($request);
        $origin = $this->allowedOrigin($request);
        $this->appendVary($response, 'Origin');
        return $origin === ''
            ? $this->withoutCorsHeaders($response)
            : $this->withCorsHeaders($request, $response, $origin);
    }

    private function isEnabled(): bool
    {
        return (bool)$this->config->get('http.cors.enabled', false);
    }

    private function isPreflightRequest(Request $request): bool
    {
        return $request->method === 'OPTIONS'
            && $this->header($request, 'Origin') !== ''
            && $this->header($request, 'Access-Control-Request-Method') !== '';
    }

    private function withCorsHeaders(Request $request, Response $response, string $origin): Response
    {
        $response->headers['Access-Control-Allow-Origin'] = $origin;
        $allowedMethods = implode(', ', $this->configList(
            'http.cors.allow_methods', ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']
        ));
        if ($allowedMethods !== '') {
            $response->headers['Access-Control-Allow-Methods'] = $allowedMethods;
        }
        $allowedHeaders = implode(', ', $this->configList(
            'http.cors.allow_headers', ['Authorization', 'Content-Type', 'Accept', 'Origin', 'X-Requested-With', 'X-Tenant-Code', 'X-Request-Id', 'X-Correlation-Id']
        ));
        if ($allowedHeaders !== '') {
            $response->headers['Access-Control-Allow-Headers'] = $allowedHeaders;
        }

        $exposeHeaders = implode(', ', $this->configList('http.cors.expose_headers', ['X-Request-Id', 'X-Correlation-Id']));
        if ($exposeHeaders !== '') {
            $response->headers['Access-Control-Expose-Headers'] = $exposeHeaders;
        }

        $maxAge = max(0, (int)$this->config->get('http.cors.max_age', 0));
        if ($maxAge > 0) {
            $response->headers['Access-Control-Max-Age'] = (string)$maxAge;
        }

        if ((bool)$this->config->get('http.cors.allow_credentials', false)) {
            $response->headers['Access-Control-Allow-Credentials'] = 'true';
        }

        $this->appendVary($response, 'Origin');
        return $response;
    }

    private function allowedOrigin(Request $request): string
    {
        $requestOrigin = $this->header($request, 'Origin');
        if (!$this->isValidOrigin($requestOrigin)) {
            return '';
        }

        $configured = $this->config->get('http.cors.allow_origin', []);
        if (!is_array($configured)) {
            return '';
        }

        foreach ($configured as $origin) {
            if (is_string($origin) && $this->isValidOrigin(trim($origin)) && hash_equals(trim($origin), $requestOrigin)) {
                return $requestOrigin;
            }
        }

        return '';
    }

    private function isValidOrigin(string $origin): bool
    {
        return preg_match('~^https?://(?:[A-Za-z0-9.-]+|\[[0-9A-Fa-f:.]+\])(?::[0-9]{1,5})?$~D', $origin) === 1;
    }

    /** @return list<string> */
    private function configList(string $key, array $default): array
    {
        $value = $this->config->get($key, $default);
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (is_string($item) && preg_match('/^[A-Za-z0-9_-]+$/D', trim($item)) === 1) {
                $items[] = trim($item);
            }
        }
        return array_values(array_unique($items));
    }

    /** @param list<string> $values */
    private function contains(array $values, string $needle): bool
    {
        return in_array($needle, $values, true);
    }

    /** @param list<string> $values @return list<string> */
    private function lowercase(array $values): array
    {
        return array_map('strtolower', $values);
    }

    /** @return list<string> */
    private function requestedHeaders(Request $request): array
    {
        $raw = $this->header($request, 'Access-Control-Request-Headers');
        if ($raw === '') {
            return [];
        }

        $headers = array_map('trim', explode(',', $raw));
        foreach ($headers as $header) {
            if ($header === '' || preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $header) !== 1) {
                return [''];
            }
        }
        return $headers;
    }

    private function header(Request $request, string $name): string
    {
        foreach ($request->headers as $headerName => $value) {
            if (is_string($headerName) && strcasecmp($headerName, $name) === 0 && is_scalar($value)) {
                return trim((string)$value);
            }
        }
        return '';
    }

    private function appendVary(Response $response, string $value): void
    {
        foreach ($response->headers as $headerName => $headerValue) {
            if (strcasecmp($headerName, 'Vary') !== 0) {
                continue;
            }
            if (trim($headerValue) === '') {
                $response->headers[$headerName] = $value;
                return;
            }
            foreach (explode(',', $headerValue) as $part) {
                if (strcasecmp(trim($part), $value) === 0) {
                    return;
                }
            }
            $response->headers[$headerName] = $headerValue . ', ' . $value;
            return;
        }
        $response->headers['Vary'] = $value;
    }

    private function withoutCorsHeaders(Response $response): Response
    {
        foreach (array_keys($response->headers) as $name) {
            if (str_starts_with(strtolower($name), 'access-control-')) {
                unset($response->headers[$name]);
            }
        }
        return $response;
    }
}
