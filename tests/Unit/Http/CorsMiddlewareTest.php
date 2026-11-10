<?php
declare(strict_types=1);

namespace Winglet\Core\Tests\Unit\Http;

use Winglet\Core\Http\Middleware\CorsMiddleware;
use Winglet\Core\Http\Request;
use Winglet\Core\Http\Response;
use Winglet\Core\Support\Config;
use Winglet\Core\Tests\TestCase;

final class CorsMiddlewareTest extends TestCase
{
    public function testDisabledCorsPassesRequestThroughWithoutCorsHeaders(): void
    {
        $response = (new CorsMiddleware($this->config(['enabled' => false])))->process(
            $this->makeRequest('GET', '/', ['Origin' => 'https://app.example']),
            static fn(Request $request): Response => Response::json(['ok' => true], 200, ['Access-Control-Allow-Origin' => '*'])
        );

        self::assertSame(200, $response->status);
        self::assertArrayNotHasKey('Access-Control-Allow-Origin', $response->headers);
    }

    public function testAllowedOriginIsReflectedFromConfiguredList(): void
    {
        $response = (new CorsMiddleware($this->config([
            'enabled' => true,
            'allow_origin' => ['https://app.example', 'https://admin.example'],
        ])))->process(
            $this->makeRequest('GET', '/', ['Origin' => 'https://admin.example']),
            static fn(Request $request): Response => Response::json(['ok' => true], 200, ['Access-Control-Allow-Origin' => '*'])
        );

        self::assertSame('https://admin.example', $response->headers['Access-Control-Allow-Origin']);
        self::assertSame('Origin', $response->headers['Vary']);
    }

    public function testDeniedOriginDoesNotReceiveAllowOriginHeader(): void
    {
        $response = (new CorsMiddleware($this->config([
            'enabled' => true,
            'allow_origin' => ['https://app.example'],
        ])))->process(
            $this->makeRequest('GET', '/', ['Origin' => 'https://evil.example']),
            static fn(Request $request): Response => Response::json(['ok' => true], 200, ['Access-Control-Allow-Origin' => '*'])
        );

        self::assertArrayNotHasKey('Access-Control-Allow-Origin', $response->headers);
        self::assertArrayNotHasKey('Access-Control-Allow-Credentials', $response->headers);
        self::assertArrayNotHasKey('Access-Control-Allow-Methods', $response->headers);
        self::assertArrayNotHasKey('Access-Control-Allow-Headers', $response->headers);
    }

    public function testCredentialsNeverUseWildcardAllowOrigin(): void
    {
        $response = (new CorsMiddleware($this->config([
            'enabled' => true,
            'allow_origin' => '*',
            'allow_credentials' => true,
        ])))->process(
            $this->makeRequest('GET', '/', ['Origin' => 'https://app.example']),
            static fn(Request $request): Response => Response::json(['ok' => true])
        );

        self::assertArrayNotHasKey('Access-Control-Allow-Origin', $response->headers);
        self::assertArrayNotHasKey('Access-Control-Allow-Credentials', $response->headers);
    }

    public function testRealPreflightIsShortCircuitedWithEmpty204Response(): void
    {
        $nextCalled = false;
        $response = (new CorsMiddleware($this->config([
            'enabled' => true,
            'allow_origin' => ['https://app.example'],
            'max_age' => 600,
            'allow_methods' => ['POST'],
            'allow_headers' => ['Authorization', 'Content-Type'],
        ])))->process(
            $this->makeRequest('OPTIONS', '/', [
                'Origin' => 'https://app.example',
                'Access-Control-Request-Method' => 'POST',
                'Access-Control-Request-Headers' => 'authorization, content-type',
            ]),
            static function (Request $request) use (&$nextCalled): Response {
                $nextCalled = true;
                return Response::json(['unexpected' => true]);
            }
        );

        self::assertFalse($nextCalled);
        self::assertSame(204, $response->status);
        self::assertSame('', $response->body);
        self::assertSame('600', $response->headers['Access-Control-Max-Age']);
        self::assertSame('https://app.example', $response->headers['Access-Control-Allow-Origin']);
        self::assertSame('Origin, Access-Control-Request-Method, Access-Control-Request-Headers', $response->headers['Vary']);
    }

    public function testPreflightFromDeniedOriginReturns403WithoutAllowHeaders(): void
    {
        $nextCalled = false;
        $response = (new CorsMiddleware($this->config([
            'enabled' => true,
            'allow_origin' => ['https://app.example'],
            'allow_methods' => ['POST'],
        ])))->process(
            $this->makeRequest('OPTIONS', '/', [
                'Origin' => 'https://evil.example',
                'Access-Control-Request-Method' => 'POST',
            ]),
            static function (Request $request) use (&$nextCalled): Response {
                $nextCalled = true;
                return Response::json(['unexpected' => true]);
            }
        );

        self::assertFalse($nextCalled);
        self::assertSame(403, $response->status);
        self::assertArrayNotHasKey('Access-Control-Allow-Origin', $response->headers);
        self::assertArrayNotHasKey('Access-Control-Allow-Methods', $response->headers);
    }

    public function testPreflightRejectsMethodOutsideAllowList(): void
    {
        $response = (new CorsMiddleware($this->config([
            'enabled' => true,
            'allow_origin' => ['https://app.example'],
            'allow_methods' => ['GET'],
        ])))->process(
            $this->makeRequest('OPTIONS', '/', [
                'Origin' => 'https://app.example',
                'Access-Control-Request-Method' => 'DELETE',
            ]),
            static fn(Request $request): Response => Response::json(['unexpected' => true])
        );

        self::assertSame(403, $response->status);
        self::assertArrayNotHasKey('Access-Control-Allow-Origin', $response->headers);
        self::assertArrayNotHasKey('Access-Control-Allow-Methods', $response->headers);
    }

    public function testOriginSuffixDoesNotMatchAnAllowedOrigin(): void
    {
        $response = (new CorsMiddleware($this->config([
            'enabled' => true,
            'allow_origin' => ['https://example.com'],
        ])))->process(
            $this->makeRequest('GET', '/', ['Origin' => 'https://example.com.attacker.test']),
            static fn(Request $request): Response => Response::json(['ok' => true])
        );

        self::assertArrayNotHasKey('Access-Control-Allow-Origin', $response->headers);
    }

    public function testOrdinaryOptionsRequestContinuesThroughPipeline(): void
    {
        $nextCalled = false;
        $response = (new CorsMiddleware($this->config(['enabled' => true])))->process(
            $this->makeRequest('OPTIONS', '/capabilities'),
            static function (Request $request) use (&$nextCalled): Response {
                $nextCalled = true;
                return Response::json(['options' => true]);
            }
        );

        self::assertTrue($nextCalled);
        self::assertSame(200, $response->status);
    }

    public function testDefaultAllowHeadersUseCurrentRequestContextHeaders(): void
    {
        $response = (new CorsMiddleware($this->config([
            'enabled' => true,
            'allow_origin' => ['https://app.example'],
        ])))->process(
            $this->makeRequest('GET', '/', ['Origin' => 'https://app.example']),
            static fn(Request $request): Response => Response::json(['ok' => true])
        );

        $headers = $response->headers['Access-Control-Allow-Headers'];
        self::assertStringContainsString('X-Request-Id', $headers);
        self::assertStringContainsString('X-Correlation-Id', $headers);
        self::assertStringNotContainsString('X-Session-Id', $headers);
    }

    /** @param array<string,mixed> $cors */
    private function config(array $cors): Config
    {
        $file = tempnam(sys_get_temp_dir(), 'winglet-cors-');
        self::assertNotFalse($file);
        file_put_contents($file, '<?php return ' . var_export(['http' => ['cors' => $cors]], true) . ';');
        $config = Config::fromPhpFile($file);
        unlink($file);
        return $config;
    }
}
