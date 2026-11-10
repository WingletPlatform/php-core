<?php
declare(strict_types=1);

namespace Winglet\Core\Kernel\Context;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Winglet\Core\Auth\AuthContext;
use Winglet\Core\Http\Context\RequestContext;
use Winglet\Core\Http\Request;
use Winglet\Core\Support\Config;
use Winglet\Core\Tenancy\TenantContext;

final class DefaultAppContextProvider implements AppContextProviderInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private Config $config,
    ) {}

    public function create(Request $request): AppContext
    {
        $auth = $request->getAttribute('auth');
        if (!$auth instanceof AuthContext) {
            $auth = AuthContext::anonymous();
        }

        $tenant = $request->getAttribute('tenant');
        if (!$tenant instanceof TenantContext) {
            $tenant = TenantContext::disabled();
        }

        $requestContext = $request->getAttribute('request_context');
        if (!$requestContext instanceof RequestContext) {
            $requestContext = RequestContext::unavailable();
        }

        return new AppContext(
            em: $this->entityManager,
            log: $this->logger,
            config: $this->config,
            auth: $auth,
            tenant: $tenant,
            request: $requestContext,
        );
    }
}
