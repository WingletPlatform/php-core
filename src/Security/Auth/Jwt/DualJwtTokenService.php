<?php
declare(strict_types=1);

namespace Winglet\Core\Security\Auth\Jwt;

use Winglet\Core\Auth\AuthContext;

/**
 * Routes one explicitly recognized token profile to exactly one validator.
 *
 * The untrusted JOSE header is only a format discriminator. The selected
 * validator remains responsible for verifying the signature and all claims.
 */
final class DualJwtTokenService implements JwtTokenServiceInterface
{
    private const MAX_TOKEN_LENGTH = 16384;

    public function __construct(
        private StandardJwtTokenService $standard,
        private LegacyJwtTokenService $legacy,
    ) {
    }

    public function parseAndValidate(string $token): AuthContext
    {
        if ($token === '' || strlen($token) > self::MAX_TOKEN_LENGTH) {
            throw TokenException::invalidToken('Token is empty or too large');
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3 || in_array('', $parts, true)) {
            throw TokenException::invalidToken('Token must have 3 non-empty parts');
        }

        [$headerSegment, $payloadSegment, $signatureSegment] = $parts;
        $headerJson = $this->decodeHeaderSegment($headerSegment);
        $header = $this->decodeHeader($headerJson);
        $type = $header['typ'] ?? null;

        if ($type === 'winglet+jwt') {
            $this->requireCanonicalSegments($parts);
            return $this->standard->parseAndValidate($token);
        }

        if (
            $type === 'JWT'
            && ($header['alg'] ?? null) === 'HS256'
            && count($header) === 2
            && preg_match('/^[a-f0-9]{64}$/D', $signatureSegment) === 1
        ) {
            $this->requireLegacySegments($headerSegment, $payloadSegment, $signatureSegment);
            return $this->legacy->parseAndValidate($token);
        }

        // Existing standard-mode compatibility tokens use Base64URL segments;
        // they remain isolated in StandardJwtTokenService, including failures.
        $this->requireCanonicalSegments($parts);
        return $this->standard->parseAndValidate($token);
    }

    /** @return array<string,mixed> */
    private function decodeHeader(string $json): array
    {
        $header = json_decode($json, true);
        if (!is_array($header) || array_is_list($header)) {
            throw TokenException::invalidData('Invalid token header');
        }

        preg_match_all('/"((?:\\\\.|[^"\\\\])*)"\s*:/', $json, $matches);
        $keys = [];
        foreach ($matches[1] as $encodedKey) {
            $key = json_decode('"' . $encodedKey . '"', true);
            if (!is_string($key) || isset($keys[$key])) {
                throw TokenException::invalidData('Ambiguous token header');
            }
            $keys[$key] = true;
        }

        foreach ($header as $key => $value) {
            if (!in_array($key, ['alg', 'typ', 'kid'], true) || !is_string($value)) {
                throw TokenException::invalidData('Unsupported token header');
            }
        }

        return $header;
    }

    private function decodeBase64Url(string $segment): string
    {
        if ($segment === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $segment) !== 1) {
            throw TokenException::invalidData('Invalid token header encoding');
        }

        $base64 = strtr($segment, '-_', '+/');
        $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);
        $decoded = base64_decode($base64, true);
        if ($decoded === false || rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=') !== $segment) {
            throw TokenException::invalidData('Invalid token header encoding');
        }

        return $decoded;
    }

    private function decodeHeaderSegment(string $segment): string
    {
        try {
            return $this->decodeBase64Url($segment);
        } catch (TokenException) {
            if (preg_match('/^[A-Za-z0-9+\/=]+$/D', $segment) !== 1) {
                throw TokenException::invalidData('Invalid token header encoding');
            }
            $decoded = base64_decode($segment, true);
            if ($decoded === false || base64_encode($decoded) !== $segment) {
                throw TokenException::invalidData('Invalid token header encoding');
            }
            return $decoded;
        }
    }

    /** @param array{string,string,string} $parts */
    private function requireCanonicalSegments(array $parts): void
    {
        foreach ($parts as $part) {
            $this->decodeBase64Url($part);
        }
    }

    private function requireLegacySegments(string $header, string $payload, string $signature): void
    {
        foreach ([$header, $payload] as $segment) {
            if (preg_match('/^[A-Za-z0-9+\/=]+$/D', $segment) !== 1) {
                throw TokenException::invalidData('Invalid legacy token encoding');
            }
            $decoded = base64_decode($segment, true);
            if ($decoded === false || base64_encode($decoded) !== $segment) {
                throw TokenException::invalidData('Invalid legacy token encoding');
            }
        }

        if (preg_match('/^[a-f0-9]{64}$/D', $signature) !== 1) {
            throw TokenException::invalidData('Invalid legacy signature encoding');
        }
    }
}
