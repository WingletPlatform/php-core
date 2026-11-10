<?php
declare(strict_types=1);

namespace Winglet\Core\Tests\Tenancy;

use Winglet\Core\Auth\AuthContext;
use Winglet\Core\Support\Config;
use Winglet\Core\Tenancy\DefaultTenantResolver;
use Winglet\Core\Tenancy\TenantResolutionException;
use Winglet\Core\Tests\TestCase;

final class DefaultTenantResolverTest extends TestCase
{
    public function testDisabledModeReturnsDisabledContext(): void
    {
        $resolver = new DefaultTenantResolver(Config::fromPhpFile('/definitely/missing.php'));
        $ctx = $resolver->resolve($this->makeRequest('GET', '/'));

        $this->assertFalse($ctx->enabled);
        $this->assertSame('disabled', $ctx->mode);
        $this->assertTrue($ctx->resolved);
        $this->assertNull($ctx->tenantCode);
    }

    public function testSingleModeReturnsDefaultTenantCode(): void
    {
        $configFile = tempnam(sys_get_temp_dir(), 'winglet_cfg_');
        file_put_contents($configFile, "<?php return ['tenancy' => ['mode' => 'single', 'default_tenant_code' => 'main']];");

        try {
            $resolver = new DefaultTenantResolver(Config::fromPhpFile($configFile));
            $ctx = $resolver->resolve($this->makeRequest('GET', '/'));

            $this->assertTrue($ctx->enabled);
            $this->assertSame('single', $ctx->mode);
            $this->assertSame('main', $ctx->tenantCode);
            $this->assertTrue($ctx->resolved);
        } finally {
            @unlink($configFile);
        }
    }

    public function testMultiHeaderModeResolvesTenantCodeFromHeader(): void
    {
        $configFile = tempnam(sys_get_temp_dir(), 'winglet_cfg_');
        file_put_contents($configFile, "<?php return ['tenancy' => ['mode' => 'multi', 'resolve_from' => 'header', 'header_name' => 'X-Tenant-Code']];");

        try {
            $resolver = new DefaultTenantResolver(Config::fromPhpFile($configFile));
            $ctx = $resolver->resolve($this->makeRequest('GET', '/', headers: ['X-Tenant-Code' => 'tenant-a']));

            $this->assertSame('multi', $ctx->mode);
            $this->assertSame('tenant-a', $ctx->tenantCode);
            $this->assertSame('header', $ctx->source);
        } finally {
            @unlink($configFile);
        }
    }

    public function testMultiAuthModeResolvesTenantCodeFromClaim(): void
    {
        $configFile = tempnam(sys_get_temp_dir(), 'winglet_cfg_');
        file_put_contents($configFile, "<?php return ['tenancy' => ['mode' => 'multi', 'resolve_from' => 'auth', 'claim_name' => 'tenant_code']];");

        try {
            $resolver = new DefaultTenantResolver(Config::fromPhpFile($configFile));
            $auth = new AuthContext(
                true,
                1,
                1,
                1,
                null,
                [],
                [],
                null,
                null,
                ['tenant_code' => 'tenant-b'],
                tenantId: 42,
                tenantContext: 'tenant',
                tokenProfile: 'canonical_v1',
            );
            $ctx = $resolver->resolve($this->makeRequest('GET', '/'), $auth);

            $this->assertSame('tenant-b', $ctx->tenantCode);
            $this->assertSame('auth', $ctx->source);
        } finally {
            @unlink($configFile);
        }
    }

    public function testMultiAuthModeRejectsLegacyTenantClaimsWithoutCanonicalScope(): void
    {
        $configFile = tempnam(sys_get_temp_dir(), 'winglet_cfg_');
        file_put_contents($configFile, "<?php return ['tenancy' => ['mode' => 'multi', 'resolve_from' => 'auth', 'claim_name' => 'tenant_code']];");

        try {
            $resolver = new DefaultTenantResolver(Config::fromPhpFile($configFile));
            $auth = AuthContext::authenticated('1', 1, [], [
                'rawPayload' => ['tenant_code' => 'tenant-b', 'tenant_id' => 42, 'token_context' => 'tenant'],
            ]);

            try {
                $resolver->resolve($this->makeRequest('GET', '/'), $auth);
                self::fail('Legacy token claims must not establish tenant scope.');
            } catch (TenantResolutionException $exception) {
                self::assertSame(403, $exception->statusCode);
            }
        } finally {
            @unlink($configFile);
        }
    }

    public function testMultiRouteModeReturnsUnresolvedWithoutRouteParamsAndResolvesWhenProvided(): void
    {
        $configFile = tempnam(sys_get_temp_dir(), 'winglet_cfg_');
        file_put_contents($configFile, "<?php return ['tenancy' => ['mode' => 'multi', 'resolve_from' => 'route', 'route_param_name' => 'tenant']];");

        try {
            $resolver = new DefaultTenantResolver(Config::fromPhpFile($configFile));
            $request = $this->makeRequest('GET', '/acme/example');

            $early = $resolver->resolve($request);
            $this->assertFalse($early->resolved);
            $this->assertSame('route', $early->source);

            $late = $resolver->resolve($request, null, ['tenant' => 'acme']);
            $this->assertTrue($late->resolved);
            $this->assertSame('acme', $late->tenantCode);
        } finally {
            @unlink($configFile);
        }
    }

    public function testMultiHeaderModeThrowsWhenHeaderMissing(): void
    {
        $configFile = tempnam(sys_get_temp_dir(), 'winglet_cfg_');
        file_put_contents($configFile, "<?php return ['tenancy' => ['mode' => 'multi', 'resolve_from' => 'header', 'header_name' => 'X-Tenant-Code']];");

        try {
            $resolver = new DefaultTenantResolver(Config::fromPhpFile($configFile));
            $this->expectException(TenantResolutionException::class);
            $resolver->resolve($this->makeRequest('GET', '/'));
        } finally {
            @unlink($configFile);
        }
    }
}
