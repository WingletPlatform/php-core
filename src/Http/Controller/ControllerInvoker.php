<?php
declare(strict_types=1);

namespace Winglet\Core\Http\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionNamedType;
use Winglet\Core\Auth\AuthContext;
use Winglet\Core\Http\Context\RequestContext;
use Winglet\Core\Http\Exception\HttpException;
use Winglet\Core\Http\Request;
use Winglet\Core\Http\Response;
use Winglet\Core\Http\Routing\RouteMatch;
use Winglet\Core\Kernel\Context\AppContext;
use Winglet\Core\Kernel\Context\AppContextProviderInterface;
use Winglet\Core\Security\AccessControl\AccessDecisionManager;
use Winglet\Core\Support\Config;
use Winglet\Core\Tenancy\TenantContext;
use Winglet\Core\Tenancy\TenantResolverInterface;

final class ControllerInvoker
{
    /**
     * @var array<string,array{rm:ReflectionMethod, specs:list<array<string,mixed>>}>
     */
    private array $callPlanCache = [];

    public function __construct(
        private LoggerInterface $logger,
        private AccessDecisionManager $access,
        private ContainerInterface $container,
        private ?AppContextProviderInterface $appContextProvider = null,
    ) {}

    public function invoke(RouteMatch $match, Request $request): Response
    {
        $controller = $match->controller;
        $action = $match->action;

        if (!class_exists($controller)) {
            $this->logger->error('Controller class missing', $this->buildRoutingErrorContext('CONTROLLER_CLASS_MISSING', $request, $match));
            throw new HttpException(500, 'Controller not found', [
                'controller' => $controller,
                'action' => $action,
            ]);
        }

        $instance = $this->container->get($controller);
        $this->access->assertAllowed($request, $match);

        $request = $request
            ->withAttribute('route_match', $match)
            ->withAttribute('route_params', $match->params);

        foreach ($match->params as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }

        $request = $request->withAttribute('tenant', $this->buildTenantContext($request, $match->params));

        $plan = $this->getCallPlan($controller, $action);
        $args = $this->bindArgsFromSpecs($plan['specs'], $request, $match->params);

        $result = $plan['rm']->invokeArgs($instance, $args);

        if ($result instanceof Response) {
            return $result;
        }
        if (is_array($result)) {
            return Response::json($result);
        }

        throw new HttpException(500, 'Invalid controller result', [
            'controller' => $controller,
            'action' => $action,
            'type' => gettype($result),
        ]);
    }

    /**
     * @return array{rm:ReflectionMethod, specs:list<array<string,mixed>>}
     */
    private function getCallPlan(string $controller, string $action): array
    {
        $key = $controller . '::' . $action;
        if (isset($this->callPlanCache[$key])) {
            return $this->callPlanCache[$key];
        }

        try {
            $rm = new ReflectionMethod($controller, $action);
        } catch (\ReflectionException $e) {
            $this->logger->error('Controller action missing', [
                'controller' => $controller,
                'action' => $action,
                'exception' => $e,
            ]);
            throw new HttpException(500, 'Controller action not found', [
                'controller' => $controller,
                'action' => $action,
            ], $e);
        }

        $specs = [];
        foreach ($rm->getParameters() as $p) {
            $name = $p->getName();
            $type = $p->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $fqcn = $type->getName();
                if ($fqcn === Request::class) {
                    $specs[] = ['kind' => 'request'];
                    continue;
                }

                if ($fqcn === AuthContext::class) {
                    $specs[] = ['kind' => 'auth_context'];
                    continue;
                }

                if ($fqcn === AppContext::class) {
                    $specs[] = ['kind' => 'app_context'];
                    continue;
                }

                if ($fqcn === TenantContext::class) {
                    $specs[] = ['kind' => 'tenant_context'];
                    continue;
                }

                $specs[] = ['kind' => 'service', 'fqcn' => $fqcn];
                continue;
            }

            $specs[] = [
                'kind' => 'route_param',
                'name' => $name,
                'type' => ($type instanceof ReflectionNamedType) ? $type->getName() : 'string',
                'nullable' => ($type instanceof ReflectionNamedType) ? $type->allowsNull() : false,
                'default' => $p->isDefaultValueAvailable() ? $p->getDefaultValue() : null,
                'hasDefault' => $p->isDefaultValueAvailable(),
            ];
        }

        return $this->callPlanCache[$key] = [
            'rm' => $rm,
            'specs' => $specs,
        ];
    }

    /**
     * @param list<array<string,mixed>> $specs
     * @param array<string,string> $routeParams
     * @return list<mixed>
     */
    private function bindArgsFromSpecs(array $specs, Request $request, array $routeParams): array
    {
        $args = [];

        foreach ($specs as $s) {
            $kind = (string)($s['kind'] ?? '');

            if ($kind === 'request') {
                $args[] = $request;
                continue;
            }

            if ($kind === 'app_context') {
                $args[] = $this->buildAppContext($request, $routeParams);
                continue;
            }

            if ($kind === 'tenant_context') {
                $args[] = $this->buildTenantContext($request, $routeParams);
                continue;
            }

            if ($kind === 'auth_context') {
                $auth = $request->getAttribute('auth');
                $args[] = $auth instanceof AuthContext ? $auth : AuthContext::anonymous();
                continue;
            }

            if ($kind === 'service') {
                $fqcn = (string)($s['fqcn'] ?? '');
                $args[] = $this->container->get($fqcn);
                continue;
            }

            if ($kind === 'route_param') {
                $name = (string)($s['name'] ?? '');
                $type = (string)($s['type'] ?? 'string');
                $nullable = (bool)($s['nullable'] ?? false);
                $hasDefault = (bool)($s['hasDefault'] ?? false);
                $default = $s['default'] ?? null;

                $raw = $routeParams[$name] ?? null;

                if ($raw === null) {
                    if ($hasDefault) {
                        $args[] = $default;
                        continue;
                    }
                    if ($nullable) {
                        $args[] = null;
                        continue;
                    }

                    throw new HttpException(400, 'Missing route parameter', [
                        'param' => $name,
                    ]);
                }

                $args[] = $this->castRouteParam($name, $raw, $type);
                continue;
            }

            throw new HttpException(500, 'Invalid call plan spec', [
                'spec' => $s,
            ]);
        }

        return $args;
    }

    private function castRouteParam(string $name, string $raw, string $type): mixed
    {
        return match ($type) {
            'int' => $this->toInt($name, $raw),
            'float' => $this->toFloat($name, $raw),
            'bool' => $this->toBool($name, $raw),
            default => $raw,
        };
    }

    private function toInt(string $name, string $raw): int
    {
        if (!preg_match('/^-?\d+$/', $raw)) {
            throw new HttpException(400, 'Invalid route parameter format', [
                'param' => $name,
                'expected' => 'int',
                'got' => $raw,
            ]);
        }
        return (int)$raw;
    }

    private function toFloat(string $name, string $raw): float
    {
        if (!is_numeric($raw)) {
            throw new HttpException(400, 'Invalid route parameter format', [
                'param' => $name,
                'expected' => 'float',
                'got' => $raw,
            ]);
        }
        return (float)$raw;
    }

    private function toBool(string $name, string $raw): bool
    {
        $v = strtolower($raw);
        if (in_array($v, ['1', 'true', 'yes', 'on'], true)) return true;
        if (in_array($v, ['0', 'false', 'no', 'off'], true)) return false;

        throw new HttpException(400, 'Invalid route parameter format', [
            'param' => $name,
            'expected' => 'bool',
            'got' => $raw,
        ]);
    }

    /**
     * @param array<string,string> $routeParams
     */
    private function buildAppContext(Request $request, array $routeParams = []): AppContext
    {
        if ($this->appContextProvider !== null) {
            return $this->appContextProvider->create($request);
        }

        if ($this->container->has(AppContextProviderInterface::class)) {
            $provider = $this->container->get(AppContextProviderInterface::class);
            if ($provider instanceof AppContextProviderInterface) {
                return $provider->create($request);
            }
        }

        $auth = $request->getAttribute('auth');
        if (!$auth instanceof AuthContext) {
            $auth = AuthContext::anonymous();
        }

        return new AppContext(
            em: $this->container->get(EntityManagerInterface::class),
            log: $this->container->get(LoggerInterface::class),
            config: $this->container->get(Config::class),
            auth: $auth,
            tenant: $this->buildTenantContext($request, $routeParams),
            request: ($requestContext = $request->getAttribute('request_context')) instanceof RequestContext
                ? $requestContext
                : RequestContext::unavailable(),
        );
    }

    /**
     * @param array<string,string> $routeParams
     */
    private function buildTenantContext(Request $request, array $routeParams = []): TenantContext
    {
        $tenant = $request->getAttribute('tenant');
        if ($tenant instanceof TenantContext && ($tenant->isResolved() || $tenant->source !== 'route')) {
            return $tenant;
        }

        if (!$this->container->has(TenantResolverInterface::class)) {
            return TenantContext::disabled();
        }

        $auth = $request->getAttribute('auth');
        if (!$auth instanceof AuthContext) {
            $auth = AuthContext::anonymous();
        }

        /** @var TenantResolverInterface $resolver */
        $resolver = $this->container->get(TenantResolverInterface::class);
        return $resolver->resolve($request, $auth, $routeParams);
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function buildRoutingErrorContext(string $errorType, Request $request, RouteMatch $match, array $extra = []): array
    {
        return array_merge([
            'errorType' => $errorType,
            'method' => $request->method,
            'requested' => [
                'originalPath' => $request->originalPath,
                'requestUri' => $_SERVER['REQUEST_URI'] ?? null,
            ],
            'basePath' => [
                'value' => $request->basePath,
                'source' => $request->basePathSource,
            ],
            'routePath' => $request->path,
            'route' => [
                'controller' => $match->controller,
                'action' => $match->action,
                'params' => $match->params,
            ],
        ], $extra);
    }
}
