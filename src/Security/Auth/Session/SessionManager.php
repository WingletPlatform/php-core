<?php
declare(strict_types=1);

namespace Winglet\Core\Security\Auth\Session;

use Winglet\Core\Auth\AuthContext;
use Winglet\Core\Http\Request;

/**
 * Compatibility projection for applications that still consume a Session object.
 *
 * @deprecated Winglet Identity owns session creation and lifecycle. New code should
 *             use the validated session id/subject from AuthContext directly.
 */
final class SessionManager
{
    public function resolveFromAuthContext(AuthContext $auth): ?Session
    {
        $sessionSubject = $auth->getSessionSubject();
        if ($sessionSubject === null) {
            return null;
        }

        return new Session(
            sessionId: $sessionSubject,
            userId: $auth->getIdentityId(),
            data: [],
        );
    }

    /**
     * Backward-compatible method. The request header is intentionally ignored;
     * only a previously validated AuthContext may establish a session.
     */
    public function resolveFromRequest(Request $request): ?Session
    {
        $auth = $request->getAttribute('auth');
        if (!$auth instanceof AuthContext) {
            return null;
        }

        return $this->resolveFromAuthContext($auth);
    }
}
