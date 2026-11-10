<?php
declare(strict_types=1);

namespace Winglet\Core\Kernel\Middleware;

use Winglet\Core\Http\Request;
use Winglet\Core\Http\Response;
use Winglet\Core\Security\Auth\Session\SessionManager;

/**
 * Adds the legacy Session request attribute as a projection of validated JWT data.
 *
 * @deprecated New application code should use AuthContext directly.
 */
final class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(private SessionManager $sessions) {}

    public function process(Request $request, callable $next): Response
    {
        return $next($request->withAttribute(
            'session',
            $this->sessions->resolveFromRequest($request),
        ));
    }
}
