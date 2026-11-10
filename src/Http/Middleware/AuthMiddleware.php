<?php
declare(strict_types=1);

namespace Winglet\Core\Http\Middleware;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Winglet\Core\Auth\AuthContext;
use Winglet\Core\Http\Exception\HttpException;
use Winglet\Core\Http\Logging\RequestLogContext;
use Winglet\Core\Http\Request;
use Winglet\Core\Http\Response;
use Winglet\Core\Kernel\Bootstrap\Application;
use Winglet\Core\Kernel\Middleware\MiddlewareInterface;
use Winglet\Core\Security\Auth\Jwt\JwtTokenServiceInterface;
use Winglet\Core\Security\Auth\Jwt\TokenException;

final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private JwtTokenServiceInterface $tokens,
        private ?LoggerInterface $logger = null,
    ) {}

    public function process(Request $request, callable $next): Response
    {
        $authHeader = (string)($request->headers['Authorization'] ?? $request->headers['authorization'] ?? '');

        if ($authHeader === '' || !str_starts_with($authHeader, 'Bearer ')) {
            $request = $request->withAttribute('auth', AuthContext::anonymous());
            return $next($request);
        }

        $token = trim(substr($authHeader, 7));
        if ($token === '') {
            $this->markInvalidToken($request, 'EMPTY_BEARER', $token, 'Empty bearer token');
            throw new HttpException(401, 'Unauthorized', [
                'token_error' => 'EMPTY_BEARER',
            ]);
        }

        try {
            $auth = $this->tokens->parseAndValidate($token);
            $logContext = $request->getAttribute('request_log_context');
            if ($auth->isAuthenticated() && $logContext instanceof RequestLogContext) {
                $logContext->markAuthenticated($auth->getClientId(), $auth->getSessionId());
            }
            $request = $request->withAttribute('auth', $auth);
            return $next($request);
        } catch (TokenException $e) {
            $this->markInvalidToken($request, $e->codeKey, $token, $e->getMessage());
            throw new HttpException(401, 'Unauthorized', [
                'token_error' => $e->codeKey,
                'reason' => $e->getMessage(),
            ], $e);
        }
    }

    private function markInvalidToken(Request $request, string $errorCode, string $token, string $reason): void
    {
        $logContext = $request->getAttribute('request_log_context');
        if ($logContext instanceof RequestLogContext) {
            $logContext->markInvalidToken();
        }

        $this->securityLogger($request)->warning('Invalid bearer token received', [
            'event' => 'invalid_bearer_token',
            'reason_code' => $errorCode,
            'reason' => $reason,
            'request_path' => $request->originalPath,
            'client_ip' => $request->server['REMOTE_ADDR'] ?? null,
            'remote_port' => isset($request->server['REMOTE_PORT']) ? (int)$request->server['REMOTE_PORT'] : null,
            'token_fingerprint' => 'sha256:' . hash('sha256', $token),
            'untrusted_claims' => $this->extractUntrustedClaims($token),
        ]);
    }

    private function securityLogger(Request $request): LoggerInterface
    {
        if ($this->logger !== null) {
            return $this->logger;
        }

        $app = $request->getAttribute('app');
        if ($app instanceof Application) {
            return $app->securityLogger;
        }

        return new NullLogger();
    }

    private function extractUntrustedClaims(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return [];
        }

        $payload = $this->decodePayload($parts[1]);
        if (!is_array($payload)) {
            return [];
        }

        $claims = [];
        $map = [
            'idi' => 'identity_id',
            'sid' => 'session_id',
            'jti' => 'identity_version',
            'slv' => 'session_level',
            'tenant_code' => 'tenant_id',
            'tenant' => 'tenant_id',
        ];

        foreach ($map as $sourceKey => $targetKey) {
            if (!array_key_exists($sourceKey, $payload)) {
                continue;
            }

            $value = $payload[$sourceKey];
            if (is_scalar($value) || $value === null) {
                $claims[$targetKey] = $value;
            }
        }

        $claims['payload_keys'] = array_values(array_filter(array_map(
            static fn(mixed $key): ?string => is_string($key) && $key !== '' ? $key : null,
            array_keys($payload)
        )));

        return $claims;
    }

    private function decodePayload(string $encodedPayload): ?array
    {
        $decoded = $this->base64UrlDecode($encodedPayload);
        if ($decoded === false) {
            return null;
        }

        $payload = json_decode($decoded, true);
        return is_array($payload) ? $payload : null;
    }

    private function base64UrlDecode(string $value): string|false
    {
        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return $decoded === false ? false : $decoded;
    }
}
