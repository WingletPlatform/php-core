<?php
declare(strict_types=1);

namespace Winglet\Core\Tests\Unit\Http;

use Winglet\Core\Auth\AuthContext;
use Winglet\Core\Http\Exception\HttpException;
use Winglet\Core\Http\Logging\RequestLogContext;
use Winglet\Core\Http\Middleware\AuthMiddleware;
use Winglet\Core\Http\Request;
use Winglet\Core\Http\Response;
use Winglet\Core\Security\Auth\Jwt\JwtTokenServiceInterface;
use Winglet\Core\Security\Auth\Jwt\TokenException;
use Winglet\Core\Tests\Support\ArrayLogger;
use Winglet\Core\Tests\TestCase;

final class AuthMiddlewareTest extends TestCase
{
    public function testRequestWithoutBearerTokenIsAnonymous(): void
    {
        $tokens = new StubTokenService();
        $captured = null;
        (new AuthMiddleware($tokens))->process(
            $this->makeRequest('GET', '/public'),
            static function (Request $request) use (&$captured): Response {
                $captured = $request->getAttribute('auth');
                return Response::json(['ok' => true]);
            }
        );

        self::assertInstanceOf(AuthContext::class, $captured);
        self::assertFalse($captured->isAuthenticated());
        self::assertSame(0, $tokens->calls);
    }

    public function testValidBearerSetsTrustedAuthAndLogContext(): void
    {
        $auth = new AuthContext(true, 12, 34, 1, 2, ['admin'], [], null, null);
        $tokens = new StubTokenService($auth);
        $requestLog = new RequestLogContext();
        $captured = null;
        (new AuthMiddleware($tokens))->process(
            $this->makeRequest('GET', '/admin', ['Authorization' => 'Bearer valid-token'])
                ->withAttribute('request_log_context', $requestLog),
            static function (Request $request) use (&$captured): Response {
                $captured = $request->getAttribute('auth');
                return Response::json(['ok' => true]);
            }
        );

        self::assertSame($auth, $captured);
        self::assertSame('authenticated', $requestLog->authState);
        self::assertSame(12, $requestLog->identityId);
        self::assertSame(34, $requestLog->sessionId);
    }

    public function testAnonymousBearerDoesNotMarkLogContextAuthenticated(): void
    {
        $requestLog = new RequestLogContext();
        (new AuthMiddleware(new StubTokenService(AuthContext::anonymous())))->process(
            $this->makeRequest('GET', '/public', ['Authorization' => 'Bearer anonymous-token'])
                ->withAttribute('request_log_context', $requestLog),
            static fn (Request $request): Response => Response::json(['ok' => true])
        );

        self::assertSame('anonymous', $requestLog->authState);
        self::assertNull($requestLog->identityId);
        self::assertNull($requestLog->sessionId);
    }

    public function testEmptyBearerIsRejectedAndLoggedWithoutRawToken(): void
    {
        $logger = new ArrayLogger();
        $requestLog = new RequestLogContext();

        try {
            (new AuthMiddleware(new StubTokenService(), $logger))->process(
                $this->makeRequest('GET', '/private', ['Authorization' => 'Bearer '])
                    ->withAttribute('request_log_context', $requestLog),
                static fn (Request $request): Response => Response::json(['ok' => true])
            );
            self::fail('Expected HttpException was not thrown.');
        } catch (HttpException $exception) {
            self::assertSame(401, $exception->statusCode);
        }

        self::assertSame('invalid_token', $requestLog->authState);
        $record = $logger->lastRecord();
        self::assertSame('EMPTY_BEARER', $record['context']['reason_code']);
        self::assertArrayHasKey('token_fingerprint', $record['context']);
        self::assertArrayNotHasKey('token', $record['context']);
    }

    public function testInvalidTokenLogsOnlyFingerprintAndClearlyUntrustedClaims(): void
    {
        $logger = new ArrayLogger();
        $tokens = new StubTokenService(exception: TokenException::securityMismatch('Bad signature'));
        $payload = $this->base64Url(json_encode(['idi' => 99, 'sid' => 77, 'secret' => 'hidden'], JSON_THROW_ON_ERROR));
        $token = 'header.' . $payload . '.signature';

        try {
            (new AuthMiddleware($tokens, $logger))->process(
                $this->makeRequest('GET', '/private', ['Authorization' => 'Bearer ' . $token]),
                static fn (Request $request): Response => Response::json(['ok' => true])
            );
            self::fail('Expected HttpException was not thrown.');
        } catch (HttpException $exception) {
            self::assertSame(401, $exception->statusCode);
        }

        $context = $logger->lastRecord()['context'];
        self::assertSame('SECURITY', $context['reason_code']);
        self::assertSame(99, $context['untrusted_claims']['identity_id']);
        self::assertSame(77, $context['untrusted_claims']['session_id']);
        self::assertContains('secret', $context['untrusted_claims']['payload_keys']);
        self::assertStringNotContainsString($token, json_encode($context, JSON_THROW_ON_ERROR));
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

final class StubTokenService implements JwtTokenServiceInterface
{
    public int $calls = 0;

    public function __construct(
        private ?AuthContext $auth = null,
        private ?TokenException $exception = null,
    ) {}

    public function parseAndValidate(string $token): AuthContext
    {
        $this->calls++;
        if ($this->exception !== null) {
            throw $this->exception;
        }
        return $this->auth ?? AuthContext::anonymous();
    }
}
