<?php
declare(strict_types=1);

/**
 * Session value object.
 *
 * Responsibilities:
 * - Holds resolved session id, user id, and session data payload.
 * - Represents authenticated/identified context for the request.
 *
 * Created by:
 * - SessionManager.
 * Attached by:
 * - SessionMiddleware via Request attributes.
 */


namespace Winglet\Core\Security\Auth\Session;

final class Session
{
    /**
     * @param array<string,mixed> $data
     */
    public function __construct(
        public readonly ?string $sessionId,
        public readonly ?int $userId,
        public readonly array $data = [],
    ) {}
}