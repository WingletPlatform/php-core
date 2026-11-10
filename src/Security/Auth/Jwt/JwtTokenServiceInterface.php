<?php
declare(strict_types=1);

namespace Winglet\Core\Security\Auth\Jwt;

use Winglet\Core\Auth\AuthContext;

/**
 * Common interface for token validators/parsers.
 *
 * Implementations:
 * - LegacyJwtTokenService   (old format)
 * - StandardJwtTokenService (RFC 7519)
 */
interface JwtTokenServiceInterface
{
    /**
     * @throws TokenException When token is invalid/expired/unauthorized.
     */
    public function parseAndValidate(string $token): AuthContext;
}
