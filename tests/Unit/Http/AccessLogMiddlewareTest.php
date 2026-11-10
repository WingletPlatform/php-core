<?php
declare(strict_types=1);

namespace Winglet\Core\Tests\Unit\Http;

use RuntimeException;
use Winglet\Core\Http\Context\RequestContext;
use Winglet\Core\Http\Exception\HttpException;
use Winglet\Core\Http\Logging\RequestLogContext;
use Winglet\Core\Http\Middleware\AccessLogMiddleware;
use Winglet\Core\Http\Request;
use Winglet\Core\Http\Response;
use Winglet\Core\Tests\Support\ArrayLogger;
use Winglet\Core\Tests\TestCase;

final class AccessLogMiddlewareTest extends TestCase
{
    public function testCopiesTrustedRequestContextAndLogsSuccessfulRequest(): void
    {
        $logger = new ArrayLogger();
        $captured = null;
        $request = $this->makeRequest('GET', '/items', ['X-Request-Id' => 'client-controlled-id'])
            ->withAttribute('request_context', new RequestContext('server-request-id', 'flow-123'));

        $response = (new AccessLogMiddleware('extended', $logger))->process(
            $request,
            static function (Request $request) use (&$captured): Response {
                $captured = $request->getAttribute('request_log_context');
                return Response::json(['ok' => true], 201);
            }
        );

        self::assertSame(201, $response->status);
        self::assertInstanceOf(RequestLogContext::class, $captured);
        self::assertSame('server-request-id', $captured->requestId);
        self::assertSame('flow-123', $captured->correlationId);
        $record = $logger->lastRecord();
        self::assertSame('HTTP access', $record['message']);
        self::assertSame('server-request-id', $record['context']['request_id']);
        self::assertSame('flow-123', $record['context']['correlation_id']);
        self::assertSame(201, $record['context']['status']);
        self::assertNotSame('client-controlled-id', $record['context']['request_id']);
    }

    public function testDetailLoggingRedactsSecretsAndFileTemporaryPath(): void
    {
        $logger = new ArrayLogger();
        $request = $this->makeRequest(
            method: 'POST',
            path: '/login',
            headers: ['Authorization' => 'Bearer secret-token', 'X-Api-Key' => 'api-secret'],
            post: ['username' => 'alice', 'password' => 'plain-secret'],
            json: ['nested' => ['access_token' => 'json-secret']],
        );

        (new AccessLogMiddleware('detail', $logger))->process(
            $request,
            static fn (Request $request): Response => Response::json(['ok' => true])
        );

        $context = $logger->lastRecord()['context'];
        self::assertSame('--REDACTED--', $context['headers']['Authorization']);
        self::assertSame('--REDACTED--', $context['headers']['X-Api-Key']);
        self::assertSame('--REDACTED--', $context['params']['POST']['password']);
        self::assertSame('--REDACTED--', $context['params']['JSON']['nested']['access_token']);
        self::assertSame('alice', $context['params']['POST']['username']);
        self::assertStringNotContainsString('secret-token', json_encode($context, JSON_THROW_ON_ERROR));
    }

    public function testLogsHttpExceptionStatusAndRethrows(): void
    {
        $logger = new ArrayLogger();

        try {
            (new AccessLogMiddleware('basic', $logger))->process(
                $this->makeRequest('GET', '/missing'),
                static fn (Request $request): Response => throw new HttpException(404, 'Not found')
            );
            self::fail('Expected HttpException was not thrown.');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->statusCode);
        }

        self::assertSame(404, $logger->lastRecord()['context']['status']);
    }

    public function testLogsUnexpectedExceptionAs500AndRethrows(): void
    {
        $logger = new ArrayLogger();

        try {
            (new AccessLogMiddleware('basic', $logger))->process(
                $this->makeRequest('GET', '/failure'),
                static fn (Request $request): Response => throw new RuntimeException('failure')
            );
            self::fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            self::assertSame('failure', $exception->getMessage());
        }

        self::assertSame(500, $logger->lastRecord()['context']['status']);
    }

    public function testNoneLevelDoesNotLog(): void
    {
        $logger = new ArrayLogger();
        $response = (new AccessLogMiddleware('none', $logger))->process(
            $this->makeRequest('GET', '/'),
            static fn (Request $request): Response => Response::json(['ok' => true])
        );

        self::assertSame(200, $response->status);
        self::assertSame([], $logger->records);
    }
}
