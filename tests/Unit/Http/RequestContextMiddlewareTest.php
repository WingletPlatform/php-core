<?php
declare(strict_types=1);

namespace Winglet\Core\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Winglet\Core\Http\Context\RequestContext;
use Winglet\Core\Http\Middleware\RequestContextMiddleware;
use Winglet\Core\Http\Request;
use Winglet\Core\Http\Response;
use Winglet\Core\Support\Config;
use Winglet\Core\Tests\TestCase;

final class RequestContextMiddlewareTest extends TestCase
{
    public function testCreatesServerRequestIdAndPropagatesValidCorrelationId(): void
    {
        $captured = null;
        $response = (new RequestContextMiddleware($this->config()))->process(
            $this->makeRequest('GET', '/', ['X-Correlation-Id' => 'flow-123']),
            static function (Request $request) use (&$captured): Response {
                $captured = $request->getAttribute('request_context');
                return Response::json(['ok' => true]);
            }
        );

        self::assertInstanceOf(RequestContext::class, $captured);
        self::assertSame('flow-123', $captured->correlationId);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $captured->requestId);
        self::assertSame($captured->requestId, $response->headers['X-Request-Id']);
        self::assertSame('flow-123', $response->headers['X-Correlation-Id']);
    }

    #[DataProvider('invalidCorrelationIds')]
    public function testRejectsInvalidInboundCorrelationId(string $value, int $maxLength = 128): void
    {
        $captured = null;
        $middleware = new RequestContextMiddleware($this->config(['max_length' => $maxLength]));
        $middleware->process(
            $this->makeRequest('GET', '/', ['X-Correlation-Id' => $value]),
            static function (Request $request) use (&$captured): Response {
                $captured = $request->getAttribute('request_context');
                return Response::json(['ok' => true]);
            }
        );

        self::assertInstanceOf(RequestContext::class, $captured);
        self::assertSame($captured->requestId, $captured->correlationId);
    }

    /** @return iterable<string,array{string,int}> */
    public static function invalidCorrelationIds(): iterable
    {
        yield 'empty' => ['', 128];
        yield 'whitespace' => ['   ', 128];
        yield 'spaces' => ['flow 123', 128];
        yield 'control character' => ["flow\n123", 128];
        yield 'too long' => ['abcdef', 5];
    }

    public function testCanDisableInboundCorrelationPropagation(): void
    {
        $captured = null;
        $middleware = new RequestContextMiddleware($this->config([
            'accept_inbound_correlation_id' => false,
        ]));
        $middleware->process(
            $this->makeRequest('GET', '/', ['X-Correlation-Id' => 'flow-123']),
            static function (Request $request) use (&$captured): Response {
                $captured = $request->getAttribute('request_context');
                return Response::json(['ok' => true]);
            }
        );

        self::assertInstanceOf(RequestContext::class, $captured);
        self::assertSame($captured->requestId, $captured->correlationId);
    }

    public function testDisabledMiddlewareDoesNotCreateContextOrHeaders(): void
    {
        $captured = 'not-called';
        $response = (new RequestContextMiddleware($this->config(['enabled' => false])))->process(
            $this->makeRequest('GET', '/'),
            static function (Request $request) use (&$captured): Response {
                $captured = $request->getAttribute('request_context');
                return Response::json(['ok' => true]);
            }
        );

        self::assertNull($captured);
        self::assertArrayNotHasKey('X-Request-Id', $response->headers);
        self::assertArrayNotHasKey('X-Correlation-Id', $response->headers);
    }

    public function testDoesNotReplaceExceptionFromDownstreamMiddleware(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('downstream failed');

        (new RequestContextMiddleware($this->config()))->process(
            $this->makeRequest('GET', '/'),
            static fn (Request $request): Response => throw new RuntimeException('downstream failed')
        );
    }

    /** @param array<string,mixed> $overrides */
    private function config(array $overrides = []): Config
    {
        $settings = array_replace([
            'enabled' => true,
            'accept_inbound_correlation_id' => true,
            'max_length' => 128,
        ], $overrides);
        $path = tempnam(sys_get_temp_dir(), 'winglet-config-');
        self::assertNotFalse($path);
        file_put_contents($path, '<?php return ' . var_export([
            'http' => ['request_context' => $settings],
        ], true) . ';');
        $config = Config::fromPhpFile($path);
        unlink($path);
        return $config;
    }
}
