<?php
declare(strict_types=1);

namespace Winglet\Core\Kernel\Context;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Winglet\Core\Auth\AuthContext;
use Winglet\Core\Support\Config;
use Winglet\Core\Http\Context\RequestContext;
use Winglet\Core\Tenancy\TenantContext;

/**
 * AppContext is a convenient bundle of request-level services.
 *
 * IMPORTANT:
 * - It may be built per-request (recommended) by ControllerInvoker / middleware.
 * - It MUST remain autowirable by DI container (constructor has no required scalars
 *   and AuthContext/TenantContext are optional).
 */
final class AppContext
{
    public readonly EntityManagerInterface $em;
    public readonly LoggerInterface $log;
    public readonly Config $config;
    public readonly AuthContext $auth;
    public readonly TenantContext $tenant;
    public readonly RequestContext $request;

    public function __construct(
        EntityManagerInterface $em,
        LoggerInterface $log,
        Config $config,
        ?AuthContext $auth = null,
        ?TenantContext $tenant = null,
        ?RequestContext $request = null,
    ) {
        $this->em = $em;
        $this->log = $log;
        $this->config = $config;
        $this->auth = $auth ?? AuthContext::anonymous();
        $this->tenant = $tenant ?? TenantContext::disabled();
        $this->request = $request ?? RequestContext::unavailable();
    }
}
