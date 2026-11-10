<?php
declare(strict_types=1);

namespace Winglet\Core\Tenancy;

use Winglet\Core\Auth\AuthContext;
use Winglet\Core\Exceptions\ConfigurationException;
use Winglet\Core\Http\Request;
use Winglet\Core\Support\Config;

final class DefaultTenantResolver implements TenantResolverInterface
{
    public function __construct(private Config $config)
    {
    }

    public function resolve(Request $request, ?AuthContext $auth = null, ?array $routeParams = null): TenantContext
    {
        $mode = $this->mode();

        return match ($mode) {
            'disabled' => TenantContext::disabled(),
            'single' => $this->resolveSingle(),
            'multi' => $this->resolveMulti($request, $auth, $routeParams),
            default => throw new ConfigurationException('Invalid tenancy.mode value: ' . $mode),
        };
    }

    private function resolveSingle(): TenantContext
    {
        $tenantCode = trim((string)$this->config->get('tenancy.default_tenant_code', 'default'));
        if ($tenantCode === '') {
            throw new ConfigurationException('tenancy.default_tenant_code is required when tenancy.mode=single');
        }

        return TenantContext::single($tenantCode, 'config');
    }

    /** @param null|array<string,string> $routeParams */
    private function resolveMulti(Request $request, ?AuthContext $auth, ?array $routeParams): TenantContext
    {
        $source = strtolower(trim((string)$this->config->get('tenancy.resolve_from', 'auth')));

        return match ($source) {
            'auth' => $this->resolveFromAuth($auth),
            'header' => $this->resolveFromHeader($request),
            'route' => $this->resolveFromRoute($request, $routeParams),
            default => throw new ConfigurationException('Invalid tenancy.resolve_from value: ' . $source),
        };
    }

    private function resolveFromAuth(?AuthContext $auth): TenantContext
    {
        if (
            $auth === null
            || $auth->getTokenProfile() !== 'canonical_v1'
            || $auth->getTenantContext() !== 'tenant'
            || !is_int($auth->getTenantId())
            || $auth->getTenantId() <= 0
        ) {
            throw new TenantResolutionException('A canonical tenant-scoped token is required.', 403, [
                'source' => 'auth',
            ]);
        }

        $claim = trim((string)$this->config->get('tenancy.claim_name', 'tenant_code'));
        $payload = $auth->getRawPayload();
        $tenantCode = isset($payload[$claim]) ? trim((string)$payload[$claim]) : '';

        if ($tenantCode === '') {
            throw new TenantResolutionException('Tenant could not be resolved from auth token.', 400, [
                'source' => 'auth',
                'claim' => $claim,
            ]);
        }

        return TenantContext::multi($tenantCode, 'auth', ['claim' => $claim]);
    }

    private function resolveFromHeader(Request $request): TenantContext
    {
        $headerName = trim((string)$this->config->get('tenancy.header_name', 'X-Tenant-Code'));
        $tenantCode = '';

        if ($headerName !== '') {
            foreach ($request->headers as $name => $value) {
                if (strcasecmp((string)$name, $headerName) === 0) {
                    $tenantCode = trim((string)$value);
                    break;
                }
            }
        }

        if ($tenantCode === '') {
            throw new TenantResolutionException('Tenant could not be resolved from request header.', 400, [
                'source' => 'header',
                'header' => $headerName,
            ]);
        }

        return TenantContext::multi($tenantCode, 'header', ['header' => $headerName]);
    }

    /** @param null|array<string,string> $routeParams */
    private function resolveFromRoute(Request $request, ?array $routeParams): TenantContext
    {
        $paramName = trim((string)$this->config->get('tenancy.route_param_name', 'tenant'));
        $tenantCode = '';

        if (is_array($routeParams) && isset($routeParams[$paramName])) {
            $tenantCode = trim((string)$routeParams[$paramName]);
        }

        if ($tenantCode === '') {
            $attr = $request->getAttribute($paramName);
            if (is_scalar($attr)) {
                $tenantCode = trim((string)$attr);
            }
        }

        if ($tenantCode === '') {
            return TenantContext::unresolved('multi', 'route', ['route_param' => $paramName]);
        }

        return TenantContext::multi($tenantCode, 'route', ['route_param' => $paramName]);
    }

    private function mode(): string
    {
        return strtolower(trim((string)$this->config->get('tenancy.mode', 'disabled')));
    }
}
