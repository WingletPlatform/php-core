<?php
declare(strict_types=1);

namespace Winglet\Core\Http\Middleware;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use Winglet\Core\Http\Context\RequestContext;
use Winglet\Core\Http\Exception\HttpException;
use Winglet\Core\Http\Logging\RequestLogContext;
use Winglet\Core\Http\Request;
use Winglet\Core\Http\Response;
use Winglet\Core\Kernel\Middleware\MiddlewareInterface;
use Winglet\Core\Kernel\Bootstrap\Application;

final class AccessLogMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $level = 'basic',
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function process(Request $request, callable $next): Response
    {
        if ($this->level === 'none') {
            return $next($request);
        }

        $context = new RequestLogContext();
        $requestContext = $request->getAttribute('request_context');
        if ($requestContext instanceof RequestContext) {
            $context->requestId = $requestContext->requestId;
            $context->correlationId = $requestContext->correlationId;
        }
        $request = $request->withAttribute('request_log_context', $context);

        $start = microtime(true);
        $response = null;
        $caught = null;

        try {
            $response = $next($request);
            return $response;
        } catch (Throwable $e) {
            $caught = $e;
            throw $e;
        } finally {
            $durationMs = round((microtime(true) - $start) * 1000, 3);
            $status = $response !== null ? $response->status : $this->statusFromThrowable($caught);
            $this->accessLogger($request)->info('HTTP access', $this->buildLogContext($request, $context, $status, $durationMs));
        }
    }

    private function buildLogContext(Request $request, RequestLogContext $context, int $status, float $durationMs): array
    {
        $log = [
            'timestamp' => (new DateTimeImmutable('now'))->format(DATE_ATOM),
            'method' => $request->method,
            'url' => $this->requestUrl($request),
            'status' => $status,
            'duration_ms' => $durationMs,
            'client_ip' => $request->server['REMOTE_ADDR'] ?? null,
            'remote_port' => isset($request->server['REMOTE_PORT']) ? (int)$request->server['REMOTE_PORT'] : null,
            'auth_state' => $context->authState,
        ];

        if ($context->requestId !== null) {
            $log['request_id'] = $context->requestId;
        }
        if ($context->correlationId !== null) {
            $log['correlation_id'] = $context->correlationId;
        }
        if ($context->tenantId !== null) {
            $log['tenant_id'] = $context->tenantId;
        }
        if ($context->sessionId !== null) {
            $log['session_id'] = $context->sessionId;
        }
        if ($context->identityId !== null) {
            $log['identity_id'] = $context->identityId;
        }

        if ($this->level === 'extended' || $this->level === 'detail') {
            $this->appendExtendedContext($log, $request, $context);
        }

        if ($this->level === 'detail') {
            $this->appendDetailContext($log, $request);
        }

        return array_filter($log, static fn(mixed $value): bool => $value !== null);
    }

    private function appendExtendedContext(array &$log, Request $request, RequestLogContext $context): void
    {
        $userAgent = $request->headers['User-Agent'] ?? $request->headers['user-agent'] ?? null;
        $referer = $request->headers['Referer'] ?? $request->headers['referer'] ?? null;
        $requestId = $context->requestId;

        if (is_string($userAgent) && $userAgent !== '') {
            $log['user_agent'] = $userAgent;
        }
        if (is_string($referer) && $referer !== '') {
            $log['referer'] = $referer;
        }
        if (is_string($requestId) && $requestId !== '') {
            $log['request_id'] = $requestId;
        }
        if ($context->route !== null) {
            $log['route'] = $context->route;
        }
        if ($context->controller !== null) {
            $log['controller'] = $context->controller;
        }
        if ($context->action !== null) {
            $log['action'] = $context->action;
        }
    }

    private function appendDetailContext(array &$log, Request $request): void
    {
        $params = [];

        if ($request->query !== []) {
            $params['GET'] = $this->sanitizeRecursive($request->query);
        }
        if ($request->post !== []) {
            $params['POST'] = $this->sanitizeRecursive($request->post);
        }
        if ($request->json !== null && $request->json !== []) {
            $params['JSON'] = $this->sanitizeRecursive($request->json);
        }
        if ($request->cookies !== []) {
            $params['COOKIE'] = $this->sanitizeRecursive($request->cookies);
        }
        if ($request->files !== []) {
            $params['FILES'] = $this->sanitizeFiles($request->files);
        }

        if ($params !== []) {
            $log['params'] = $params;
        }

        $headers = $this->sanitizeHeaders($request->headers);
        if ($headers !== []) {
            $log['headers'] = $headers;
        }
    }

    private function sanitizeHeaders(array $headers): array
    {
        $result = [];
        foreach ($headers as $name => $value) {
            $key = (string)$name;
            if ($this->isSensitiveKey($key)) {
                $result[$key] = $this->redactedPlaceholder($value);
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $result[$key] = $value;
                continue;
            }

            $result[$key] = $this->sanitizeRecursive($value, $key);
        }

        return $result;
    }

    private function sanitizeFiles(array $files): array
    {
        return $this->sanitizeFilesRecursive($files);
    }

    private function sanitizeFilesRecursive(array $value): array
    {
        $result = [];

        foreach ($value as $key => $item) {
            $key = (string)$key;

            if (is_array($item)) {
                $result[$key] = $this->sanitizeFilesRecursive($item);
                continue;
            }

            if (in_array($key, ['name', 'type', 'tmp_name', 'error', 'size'], true)) {
                $result[$key] = $key === 'tmp_name'
                    ? $this->redactedPlaceholder($item)
                    : $item;
            }
        }

        return $result;
    }

    private function sanitizeRecursive(mixed $value, ?string $currentKey = null): mixed
    {
        if ($currentKey !== null && $this->isSensitiveKey($currentKey)) {
            return $this->redactedPlaceholder($value);
        }

        if (!is_array($value)) {
            return $value;
        }

        $result = [];
        foreach ($value as $key => $item) {
            $result[(string)$key] = $this->sanitizeRecursive($item, (string)$key);
        }

        return $result;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);
        $compact = preg_replace('/[^a-z0-9]+/', '', $normalized) ?? $normalized;

        foreach (['password', 'passwd', 'secret', 'token', 'authorization', 'apikey', 'cookie', 'session', 'csrf'] as $needle) {
            if (str_contains($compact, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function redactedPlaceholder(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '--EMPTY--';
        }

        if (is_array($value) && $value === []) {
            return '--EMPTY--';
        }

        return '--REDACTED--';
    }

    private function requestUrl(Request $request): string
    {
        $uri = $request->server['REQUEST_URI'] ?? null;
        if (is_string($uri) && $uri !== '') {
            return $uri;
        }

        if ($request->query === []) {
            return $request->originalPath;
        }

        return $request->originalPath . '?' . http_build_query($request->query);
    }

    private function statusFromThrowable(?Throwable $throwable): int
    {
        if ($throwable instanceof HttpException) {
            return $throwable->statusCode;
        }

        return 500;
    }

    private function accessLogger(Request $request): LoggerInterface
    {
        if ($this->logger !== null) {
            return $this->logger;
        }

        $app = $request->getAttribute('app');
        if ($app instanceof Application) {
            return $app->accessLogger;
        }

        return new NullLogger();
    }
}
