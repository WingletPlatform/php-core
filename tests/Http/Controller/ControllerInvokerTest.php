<?php
declare(strict_types=1);

namespace Winglet\Core\Tests\Http\Controller;

use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Winglet\Core\Auth\AuthContext;
use Winglet\Core\Http\Controller\ControllerInvoker;
use Winglet\Core\Http\Request;
use Winglet\Core\Http\Routing\RouteMatch;
use Winglet\Core\Security\AccessControl\AccessDecisionManager;
use Winglet\Core\Tenancy\TenantContext;
use Winglet\Core\Tenancy\TenantResolverInterface;
use Winglet\Core\Tests\TestCase;

final class ControllerInvokerTest extends TestCase
{
    public function testInjectsAuthContextFromRequestAttributes(): void
    {
        $controller = new class {
            public function handle(Request $req, AuthContext $auth): array
            {
                return [
                    'sid' => $auth->getSessionId(),
                    'authenticated' => $auth->isAuthenticated(),
                ];
            }
        };

        $container = new class($controller) implements ContainerInterface {
            public function __construct(private object $controller) {}

            public function get(string $id): mixed
            {
                if ($id === AuthContext::class) {
                    throw new RuntimeException('AuthContext must not be resolved from container');
                }

                if ($id === $this->controller::class) {
                    return $this->controller;
                }

                throw new RuntimeException('Unknown service: ' . $id);
            }

            public function has(string $id): bool
            {
                return $id === $this->controller::class;
            }
        };

        $logger = new NullLogger();
        $adm = new AccessDecisionManager($container);
        $invoker = new ControllerInvoker($logger, $adm, $container);

        $auth = AuthContext::authenticated(
            '123',
            2,
            ['g1'],
            [
                'sessionId' => 987,
                'identityVersion' => 5,
            ]
        );

        $req = $this->makeRequest('GET', '/session')
            ->withAttribute('auth', $auth);

        $match = new RouteMatch(
            controller: $controller::class,
            action: 'handle',
            params: [],
            access: null
        );

        $resp = $invoker->invoke($match, $req);

        $this->assertSame(200, $resp->status);

        $payload = json_decode($resp->body, true);
        $this->assertIsArray($payload);
        $this->assertSame(987, $payload['sid']);
        $this->assertTrue($payload['authenticated']);
    }

    public function testInjectsAnonymousAuthContextWhenMissingFromRequestAttributes(): void
    {
        $controller = new class {
            public function handle(AuthContext $auth): array
            {
                return [
                    'sid' => $auth->getSessionId(),
                    'authenticated' => $auth->isAuthenticated(),
                ];
            }
        };

        $container = new class($controller) implements ContainerInterface {
            public function __construct(private object $controller) {}

            public function get(string $id): mixed
            {
                if ($id === AuthContext::class) {
                    throw new RuntimeException('AuthContext must not be resolved from container');
                }

                if ($id === $this->controller::class) {
                    return $this->controller;
                }

                throw new RuntimeException('Unknown service: ' . $id);
            }

            public function has(string $id): bool
            {
                return $id === $this->controller::class;
            }
        };

        $logger = new NullLogger();

        $adm = new AccessDecisionManager($container);
        $invoker = new ControllerInvoker($logger, $adm, $container);

        $req = $this->makeRequest('GET', '/session');

        $match = new RouteMatch(
            controller: $controller::class,
            action: 'handle',
            params: [],
            access: null
        );

        $resp = $invoker->invoke($match, $req);

        $this->assertSame(200, $resp->status);

        $payload = json_decode($resp->body, true);
        $this->assertIsArray($payload);
        $this->assertNull($payload['sid']);
        $this->assertFalse($payload['authenticated']);
    }

    public function testInjectsTenantContextFromRequestAttributes(): void
    {
        $controller = new class {
            public function handle(TenantContext $tenant): array
            {
                return [
                    'enabled' => $tenant->enabled,
                    'mode' => $tenant->mode,
                    'tenantCode' => $tenant->tenantCode,
                    'resolved' => $tenant->resolved,
                ];
            }
        };

        $tenant = TenantContext::multi('tenant-a', 'header');

        $container = new class($controller) implements ContainerInterface {
            public function __construct(private object $controller) {}

            public function get(string $id): mixed
            {
                if ($id === AuthContext::class) {
                    throw new RuntimeException('AuthContext must not be resolved from container');
                }
                if ($id === TenantContext::class) {
                    throw new RuntimeException('TenantContext must not be resolved from container');
                }
                if ($id === TenantResolverInterface::class) {
                    throw new RuntimeException('TenantResolverInterface must not be resolved when request already has tenant');
                }
                if ($id === $this->controller::class) {
                    return $this->controller;
                }

                throw new RuntimeException('Unknown service: ' . $id);
            }

            public function has(string $id): bool
            {
                return $id === $this->controller::class;
            }
        };

        $logger = new NullLogger();
        $adm = new AccessDecisionManager($container);
        $invoker = new ControllerInvoker($logger, $adm, $container);

        $req = $this->makeRequest('GET', '/tenant')
            ->withAttribute('tenant', $tenant);

        $match = new RouteMatch(
            controller: $controller::class,
            action: 'handle',
            params: [],
            access: null
        );

        $resp = $invoker->invoke($match, $req);
        $payload = json_decode($resp->body, true);

        $this->assertSame('tenant-a', $payload['tenantCode']);
        $this->assertTrue($payload['resolved']);
    }


    public function testResolvedRouteTenantIsWrittenBackToRequestAttributes(): void
    {
        $controller = new class {
            public function handle(Request $request, TenantContext $tenant): array
            {
                $requestTenant = $request->getAttribute('tenant');

                return [
                    'requestTenantCode' => $requestTenant instanceof TenantContext ? $requestTenant->tenantCode : null,
                    'injectedTenantCode' => $tenant->tenantCode,
                    'requestTenantResolved' => $requestTenant instanceof TenantContext ? $requestTenant->resolved : false,
                ];
            }
        };

        $resolver = new class implements TenantResolverInterface {
            public function resolve(Request $request, ?AuthContext $auth = null, ?array $routeParams = null): TenantContext
            {
                if (is_array($routeParams) && isset($routeParams['tenant'])) {
                    return TenantContext::multi((string)$routeParams['tenant'], 'route');
                }

                return TenantContext::unresolved('multi', 'route');
            }
        };

        $container = new class($controller, $resolver) implements ContainerInterface {
            public function __construct(private object $controller, private TenantResolverInterface $resolver) {}

            public function get(string $id): mixed
            {
                if ($id === $this->controller::class) {
                    return $this->controller;
                }
                if ($id === TenantResolverInterface::class) {
                    return $this->resolver;
                }
                if ($id === AuthContext::class) {
                    throw new RuntimeException('AuthContext must not be resolved from container');
                }

                throw new RuntimeException('Unknown service: ' . $id);
            }

            public function has(string $id): bool
            {
                return in_array($id, [$this->controller::class, TenantResolverInterface::class], true);
            }
        };

        $logger = new NullLogger();
        $adm = new AccessDecisionManager($container);
        $invoker = new ControllerInvoker($logger, $adm, $container);

        $req = $this->makeRequest('GET', '/tenant/acme')
            ->withAttribute('tenant', TenantContext::unresolved('multi', 'route'));

        $match = new RouteMatch(
            controller: $controller::class,
            action: 'handle',
            params: ['tenant' => 'acme'],
            access: null
        );

        $resp = $invoker->invoke($match, $req);
        $payload = json_decode($resp->body, true);

        $this->assertSame('acme', $payload['requestTenantCode']);
        $this->assertSame('acme', $payload['injectedTenantCode']);
        $this->assertTrue($payload['requestTenantResolved']);
    }
    public function testWritesRouteParametersToRequestAttributes(): void
    {
        $controller = new class {
            public function handle(Request $request, int $id): array
            {
                return [
                    'idArgument' => $id,
                    'idAttribute' => $request->getAttribute('id'),
                    'routeParams' => $request->getAttribute('route_params'),
                ];
            }
        };

        $container = new class($controller) implements ContainerInterface {
            public function __construct(private object $controller) {}

            public function get(string $id): mixed
            {
                if ($id === $this->controller::class) {
                    return $this->controller;
                }

                throw new RuntimeException('Unknown service: ' . $id);
            }

            public function has(string $id): bool
            {
                return $id === $this->controller::class;
            }
        };

        $logger = new NullLogger();
        $adm = new AccessDecisionManager($container);
        $invoker = new ControllerInvoker($logger, $adm, $container);

        $req = $this->makeRequest('PUT', '/collaterals/442/details');

        $match = new RouteMatch(
            controller: $controller::class,
            action: 'handle',
            params: ['id' => '442'],
            access: null
        );

        $resp = $invoker->invoke($match, $req);
        $payload = json_decode($resp->body, true);

        $this->assertSame(442, $payload['idArgument']);
        $this->assertSame('442', $payload['idAttribute']);
        $this->assertSame(['id' => '442'], $payload['routeParams']);
    }

    public function testRawControllerResponseIsReturnedWithoutJsonWrapping(): void
    {
        $controller = new class {
            public function handle(): \Winglet\Core\Http\Response
            {
                return \Winglet\Core\Http\Response::download(
                    body: "sep=;\nPantin numero;Rekisteritunnus\n1;ABC-123\n",
                    filename: 'valid-offers.csv',
                    contentType: 'text/csv; charset=UTF-8'
                );
            }
        };

        $container = new class($controller) implements ContainerInterface {
            public function __construct(private object $controller) {}

            public function get(string $id): mixed
            {
                if ($id === $this->controller::class) {
                    return $this->controller;
                }

                throw new RuntimeException('Unknown service: ' . $id);
            }

            public function has(string $id): bool
            {
                return $id === $this->controller::class;
            }
        };

        $logger = new NullLogger();
        $adm = new AccessDecisionManager($container);
        $invoker = new ControllerInvoker($logger, $adm, $container);

        $req = $this->makeRequest('GET', '/exports/valid-offers.csv', query: ['download' => '1']);
        $match = new RouteMatch(
            controller: $controller::class,
            action: 'handle',
            params: [],
            access: null
        );

        $resp = $invoker->invoke($match, $req);

        $this->assertSame(200, $resp->status);
        $this->assertSame('text/csv; charset=UTF-8', $resp->headers['Content-Type']);
        $this->assertStringContainsString('attachment', $resp->headers['Content-Disposition']);
        $this->assertStringStartsWith('sep=;', $resp->body);
        $this->assertStringStartsNotWith('{', $resp->body);
        $this->assertNull(json_decode($resp->body, true));
    }


}
