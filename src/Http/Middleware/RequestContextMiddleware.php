<?php
declare(strict_types=1);

namespace Winglet\Core\Http\Middleware;

use Winglet\Core\Http\Context\RequestContext;
use Winglet\Core\Http\Logging\RequestLogContext;
use Winglet\Core\Http\Request;
use Winglet\Core\Http\Response;
use Winglet\Core\Kernel\Middleware\MiddlewareInterface;
use Winglet\Core\Support\Config;

final class RequestContextMiddleware implements MiddlewareInterface
{
    public function __construct(private Config $config) {}

    public function process(Request $request, callable $next): Response
    {
        if (!(bool)$this->config->get('http.request_context.enabled', true)) {
            return $next($request);
        }

        $requestId = bin2hex(random_bytes(16));
        $requestHeader = (string)$this->config->get(
            'http.request_context.request_id_header',
            'X-Request-Id'
        );
        $correlationHeader = (string)$this->config->get(
            'http.request_context.correlation_id_header',
            'X-Correlation-Id'
        );
        $correlationId = $this->validInboundCorrelationId($request, $correlationHeader) ?? $requestId;
        $context = new RequestContext($requestId, $correlationId);

        $logContext = $request->getAttribute('request_log_context');
        if ($logContext instanceof RequestLogContext) {
            $logContext->requestId = $requestId;
            $logContext->correlationId = $correlationId;
        }

        $response = $next($request->withAttribute('request_context', $context));

        return $response
            ->withHeader($requestHeader, $requestId)
            ->withHeader($correlationHeader, $correlationId);
    }

    private function validInboundCorrelationId(Request $request, string $headerName): ?string
    {
        if (!(bool)$this->config->get('http.request_context.accept_inbound_correlation_id', true)) {
            return null;
        }

        $value = $request->headers[$headerName]
            ?? $request->headers[strtolower($headerName)]
            ?? null;

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        $maxLength = (int)$this->config->get('http.request_context.max_length', 128);

        if (
            $value === ''
            || strlen($value) > $maxLength
            || preg_match('/^[A-Za-z0-9._:\-]+$/D', $value) !== 1
        ) {
            return null;
        }

        return $value;
    }
}
