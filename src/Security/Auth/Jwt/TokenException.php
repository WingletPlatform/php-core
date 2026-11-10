<?php
declare(strict_types=1);

namespace Winglet\Core\Security\Auth\Jwt;


/**
 * Internal token validation exception used to map errors to HTTP responses.
 *
 * Thrown from:
 * - LegacyJwtTokenService
 * Caught by:
 * - AuthMiddleware
 */
final class TokenException extends \RuntimeException
{
    private function __construct(public readonly string $codeKey, string $message)
    {
        parent::__construct($message);
    }

    public static function invalidToken(string $message): self { return new self('INVALIDTOKEN', $message); }
    public static function invalidData(string $message): self { return new self('INVALIDDATA', $message); }
    public static function securityMismatch(string $message): self { return new self('SECURITY', $message); }
    public static function expired(string $message): self { return new self('EXPIRED', $message); }
}