<?php
declare(strict_types=1);

namespace Winglet\Core\Http\Middleware;

use Winglet\Core\Auth\AuthContext;
use Winglet\Core\Http\Logging\RequestLogContext;
use Winglet\Core\Http\Request;
use Winglet\Core\Http\Response;
use Winglet\Core\Kernel\Middleware\MiddlewareInterface;
use Winglet\Core\Tenancy\TenantResolverInterface;

final class TenantMiddleware implements MiddlewareInterface
{
    public function __construct(private TenantResolverInterface $resolver)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $auth = $request->getAttribute('auth');
        if (!$auth instanceof AuthContext) {
            $auth = AuthContext::anonymous();
        }

        $tenant = $this->resolver->resolve($request, $auth, null);
        $logContext = $request->getAttribute('request_log_context');
        if ($logContext instanceof RequestLogContext) {
            $logContext->setTenantId($tenant->getTenantCode());
        }

        return $next($request->withAttribute('tenant', $tenant));
    }
}
