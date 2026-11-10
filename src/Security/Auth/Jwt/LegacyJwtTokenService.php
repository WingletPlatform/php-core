<?php
declare(strict_types=1);

namespace Winglet\Core\Security\Auth\Jwt;

use DateTimeImmutable;
use Throwable;
use Psr\Log\LoggerInterface;
use Winglet\Core\Auth\AuthContext;
use Winglet\Core\Exceptions\RuntimeException;
use Winglet\Core\Support\Config;

/**
 * Validates and parses legacy "JWT-like" token format used by old system.
 *
 * Token format:
 * - "<base64(header)>.<base64(payload)>.<hex(hmac_sha256(header.payload, secret))>"
 * - payload is base64_encode(json)
 * - exp is a string datetime (parsed with DateTimeImmutable)
 */
final class LegacyJwtTokenService implements JwtTokenServiceInterface
{
    public function __construct(
        private Config $config,
        private LoggerInterface $logger,
    ) {}

    public function parseAndValidate(string $token): AuthContext
    {
        if ($token === '' || strlen($token) > 16384) {
            throw TokenException::invalidToken('Token is empty or too large');
        }

        $secretKeyPath = (string)$this->config->get('authentication.jwt.legacy.secret_config_key', 'environment.Secret');
        $secret = (string)$this->config->get($secretKeyPath, '');

        if ($secret === '') {
            throw new RuntimeException("Missing {$secretKeyPath} for legacy token validation");
        }

        if (!str_contains($token, '.')) {
            throw TokenException::invalidToken('Token missing required parts');
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw TokenException::invalidToken('Token is invalid. Check that whole token was transferred');
        }

        [$headerB64, $payloadB64, $sig] = $parts;

        if (preg_match('/^[A-Za-z0-9+\/=]+$/D', $headerB64) !== 1) {
            throw TokenException::invalidData('Invalid header base64');
        }
        $headerJson = base64_decode($headerB64, true);
        if ($headerJson === false || base64_encode($headerJson) !== $headerB64) {
            throw TokenException::invalidData('Invalid header base64');
        }
        $header = json_decode($headerJson, true);
        preg_match_all('/"((?:\\\\.|[^"\\\\])*)"\s*:/', $headerJson, $headerKeys);
        $seenHeaderKeys = [];
        foreach ($headerKeys[1] as $encodedKey) {
            $key = json_decode('"' . $encodedKey . '"', true);
            if (!is_string($key) || isset($seenHeaderKeys[$key])) {
                throw TokenException::invalidData('Ambiguous legacy token header');
            }
            $seenHeaderKeys[$key] = true;
        }
        if (
            !is_array($header)
            || ($header['alg'] ?? null) !== 'HS256'
            || ($header['typ'] ?? null) !== 'JWT'
            || count($header) !== 2
        ) {
            throw TokenException::invalidToken('Invalid legacy token header');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $sig) !== 1) {
            throw TokenException::invalidData('Invalid legacy signature encoding');
        }

        // Must match old system exactly: hash_hmac sha256 over "header.payload", default output (hex).
        $expected = hash_hmac('sha256', "{$headerB64}.{$payloadB64}", $secret);

        if (!hash_equals($expected, $sig)) {
            $this->logger->error('Security hash mismatch');
            throw TokenException::securityMismatch('Security hash mismatch');
        }

        if (preg_match('/^[A-Za-z0-9+\/=]+$/D', $payloadB64) !== 1) {
            throw TokenException::invalidData('Invalid payload base64');
        }
        $payloadJson = base64_decode($payloadB64, true);
        if ($payloadJson === false || base64_encode($payloadJson) !== $payloadB64) {
            throw TokenException::invalidData('Invalid payload base64');
        }

        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            throw TokenException::invalidData('Invalid session data');
        }

        // Validate required fields based on legacy handler
        foreach (['exp', 'jti', 'idi', 'sid', 'slv'] as $k) {
            if (!array_key_exists($k, $payload)) {
                throw TokenException::invalidData('Invalid session data');
            }
        }

        if (!is_string($payload['exp']) || trim($payload['exp']) === '') {
            throw TokenException::invalidData('Invalid exp');
        }

        $identityVersion = $this->readIntegerClaim($payload, 'jti');
        $sessionId = $this->readIntegerClaim($payload, 'sid');
        $sessionLevel = $this->readIntegerClaim($payload, 'slv');
        $identityId = $this->readIntegerClaim($payload, 'idi', nullable: true);

        try {
            $expiresAt = new DateTimeImmutable($payload['exp']);
        } catch (Throwable) {
            throw TokenException::invalidData('Invalid exp');
        }
        if ($expiresAt < new DateTimeImmutable('now')) {
            $this->logger->error('Session expired');
            throw TokenException::expired('Session expired');
        }

        $privileges = $this->readPrivileges($payload['privileges'] ?? []);

        // "groups" support (new requirement): accept array or object, optional
        $groupsRaw = $payload['groups'] ?? ($payload['grp'] ?? []);
        $groups = array_values(array_filter($this->readArrayLike($groupsRaw), fn($v) => is_string($v) && $v !== ''));

        $clientIdStr = $identityId === null ? null : (string)$identityId;
        $level = $sessionLevel;

        $claims = [
            'sessionId' => $sessionId,
            'identityVersion' => $identityVersion,
            'privileges' => $privileges,
            'expiresAt' => $expiresAt,
            'rawPayload' => $payload,
            'legacy' => true,
        ];

        if ($sessionLevel === 0) {
            return AuthContext::anonymous();
        }

        return AuthContext::authenticated(
            clientId: $clientIdStr,
            sessionLevel: $level,
            groups: $groups,
            claims: $claims
        );
    }

    /**
     * Legacy identifiers are transported as JSON integers or decimal strings.
     * Reject floats, exponent notation, negative values and integer overflow so
     * malformed tokens cannot collapse to the same PHP integer after casting.
     *
     * @param array<string,mixed> $payload
     */
    private function readIntegerClaim(array $payload, string $name, bool $nullable = false): ?int
    {
        $value = $payload[$name] ?? null;
        if ($value === null) {
            if ($nullable) {
                return null;
            }
            throw TokenException::invalidData("Invalid {$name}");
        }

        if (is_int($value)) {
            if ($value < 0) {
                throw TokenException::invalidData("Invalid {$name}");
            }
            return $value;
        }

        if (!is_string($value) || preg_match('/^\d+$/D', $value) !== 1) {
            throw TokenException::invalidData("Invalid {$name}");
        }

        $normalized = ltrim($value, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        $max = (string)PHP_INT_MAX;
        if (strlen($normalized) > strlen($max)
            || (strlen($normalized) === strlen($max) && strcmp($normalized, $max) > 0)) {
            throw TokenException::invalidData("Invalid {$name}");
        }

        return (int)$normalized;
    }

    private function readArrayLike(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (is_object($value)) return get_object_vars($value);
        return [];
    }

    /** @return list<string> */
    private function readPrivileges(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return [];
        }

        foreach ($value as $privilege) {
            if (!is_string($privilege) || $privilege === '') {
                return [];
            }
        }

        return $value;
    }
}
