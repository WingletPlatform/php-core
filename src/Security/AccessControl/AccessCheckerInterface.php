<?php
declare(strict_types=1);

namespace Winglet\Core\Security\AccessControl;

use Winglet\Core\Auth\AuthContext;
use Winglet\Core\Http\Request;

interface AccessCheckerInterface
{
    /**
     * Palauta true jos sallitaan, false jos kielletään.
     * Voit heittää poikkeuksen tarkemmalla viestillä.
     *
     * @param array<string,string> $routeParams
     */
    public function check(Request $request, AuthContext $auth, array $routeParams): bool;
}