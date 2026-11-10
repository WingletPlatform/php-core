<?php
declare(strict_types=1);

namespace Winglet\Core\Tests\Unit\Http;

use Winglet\Core\Auth\AuthContext;
use Winglet\Core\Http\Logging\RequestLogContext;
use Winglet\Core\Http\Middleware\TenantMiddleware;
use Winglet\Core\Http\Request;
use Winglet\Core\Http\Response;
use Winglet\Core\Tenancy\TenantContext;
use Winglet\Core\Tenancy\TenantResolverInterface;
use Winglet\Core\Tests\TestCase;

final class TenantMiddlewareTest extends TestCase
{
    public function testResolvedTenantIsAddedToRequestAndLogContext(): void
    {
        $resolver = new CapturingTenantResolver(TenantContext::multi('tenant-42', 'auth'));
        $logContext = new RequestLogContext();
        $capturedTenant = null;

        $response = (new TenantMiddleware($resolver))->process(
            $this->makeRequest('GET', '/')
                ->withAttribute('auth', AuthContext::anonymous())
                ->withAttribute('request_log_context', $logContext),
            static function (Request $request) use (&$capturedTenant): Response {
                $capturedTenant = $request->getAttribute('tenant');
                return Response::json(['ok' => true]);
            }
        );

        self::assertSame(200, $response->status);
        self::assertSame($resolver->tenant, $capturedTenant);
        self::assertSame('tenant-42', $logContext->tenantId);
        self::assertInstanceOf(AuthContext::class, $resolver->auth);
    }

    public function testMissingAuthContextIsNormalizedToAnonymous(): void
    {
        $resolver = new CapturingTenantResolver(TenantContext::disabled());

        (new TenantMiddleware($resolver))->process(
            $this->makeRequest('GET', '/'),
            static fn(Request $request): Response => Response::json(['ok' => true])
        );

        self::assertInstanceOf(AuthContext::class, $resolver->auth);
        self::assertFalse($resolver->auth->isAuthenticated());
    }
}

final class CapturingTenantResolver implements TenantResolverInterface
{
    public ?AuthContext $auth = null;

    public function __construct(public TenantContext $tenant) {}

    public function resolve(Request $request, ?AuthContext $auth = null, ?array $routeParams = null): TenantContext
    {
        $this->auth = $auth;
        return $this->tenant;
    }
}
