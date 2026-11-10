<?php
declare(strict_types=1);

namespace Winglet\Core\Security\AccessControl;

use Winglet\Core\Auth\AuthContext;
use Winglet\Core\Http\Request;

interface AccessFailureContextProviderInterface
{
    /**
     * @param array<string,string> $routeParams
     * @return array<string,mixed>
     */
    public function buildFailureContext(Request $request, AuthContext $auth, array $routeParams): array;
}
