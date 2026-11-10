<?php
declare(strict_types=1);

namespace Winglet\Core\Tenancy;

use Winglet\Core\Auth\AuthContext;
use Winglet\Core\Http\Request;

interface TenantResolverInterface
{
    /**
     * @param null|array<string,string> $routeParams
     */
    public function resolve(Request $request, ?AuthContext $auth = null, ?array $routeParams = null): TenantContext;
}
