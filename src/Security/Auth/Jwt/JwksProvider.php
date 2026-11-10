<?php
declare(strict_types=1);

namespace Winglet\Core\Security\Auth\Jwt;

use Psr\Log\LoggerInterface;
use Winglet\Core\Support\Config;

/**
 * JWKS (JWK Set) fetcher + file cache with hardening:
 * - URL allowlist (allowed_urls)
 * - Issuer pinning (issuer_pins: issuer => jwks_url)
 *
 * Supports standard RSA JWKs using either x5c or n/e.
 */
final class JwksProvider
{
    public function __construct(
        private Config $config,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return array<string,array{alg:string, public_key_pem:string}>
     */
    public function getVerificationKeys(bool $forceRefresh = false): array
    {
        $jwksCfg = (array)$this->config->get('authentication.jwt.standard.jwks', []);
        $enabled = (bool)($jwksCfg['enabled'] ?? false);
        if (!$enabled) {
            return [];
        }

        $url = (string)($jwksCfg['url'] ?? '');
        if ($url === '') {
            return [];
        }

        if (!$this->isUrlAllowedAndPinned($url, $jwksCfg)) {
            // Hard reject JWKS usage if policy fails
            return [];
        }

        $ttl = (int)($jwksCfg['cache_ttl_seconds'] ?? 300);
        $timeout = (int)($jwksCfg['timeout_seconds'] ?? 2);
        $cacheDir = $jwksCfg['cache_dir'] ?? null;
        $useStale = (bool)($jwksCfg['use_stale_on_error'] ?? true);
        $maxStale = (int)($jwksCfg['max_stale_seconds'] ?? 3600);

        $cachePath = $this->cachePathForUrl($url, is_string($cacheDir) ? $cacheDir : null);

        $cached = $this->readCache($cachePath);
        $now = time();

        // Try cached first if still fresh
        if (!$forceRefresh && $cached && isset($cached['fetched_at'], $cached['jwks']) && is_int($cached['fetched_at']) && is_array($cached['jwks'])) {
            $age = $now - $cached['fetched_at'];
            if ($ttl > 0 && $age >= 0 && $age <= $ttl) {
                return $this->jwksToKeys($cached['jwks']);
            }
        }

        // Fetch
        try {
            $jwks = $this->fetchJwks($url, $timeout);
            $this->writeCache($cachePath, [
                'fetched_at' => $now,
                'jwks' => $jwks,
            ]);
            return $this->jwksToKeys($jwks);
        } catch (\Throwable $e) {
            $this->logger->warning('JWKS fetch failed', [
                'url' => $url,
                'cache' => $cachePath,
                'exception' => $e,
            ]);

            if ($useStale && $cached && isset($cached['fetched_at'], $cached['jwks']) && is_int($cached['fetched_at']) && is_array($cached['jwks'])) {
                $age = $now - $cached['fetched_at'];
                if ($age >= 0 && $age <= max($ttl, 0) + max($maxStale, 0)) {
                    return $this->jwksToKeys($cached['jwks']);
                }
            }

            return [];
        }
    }

    /**
     * Enforces:
     * - allowed_urls allowlist (if provided)
     * - issuer pinning via issuer_pins (if standard issuer matches a pin)
     *
     * @param array<string,mixed> $jwksCfg
     */
    private function isUrlAllowedAndPinned(string $url, array $jwksCfg): bool
    {
        $allowed = $jwksCfg['allowed_urls'] ?? [];
        if (is_array($allowed) && $allowed !== []) {
            $ok = false;
            foreach ($allowed as $a) {
                if (is_string($a) && $a !== '' && hash_equals($a, $url)) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                $this->logger->warning('JWKS URL not in allowlist', [
                    'url' => $url,
                ]);
                return false;
            }
        }

        // issuer pinning
        $issuer = (string)$this->config->get('authentication.jwt.standard.issuer', '');
        $pins = $jwksCfg['issuer_pins'] ?? [];

        if ($issuer !== '' && is_array($pins) && $pins !== []) {
            // only enforce if issuer exists in pins
            $pinned = $pins[$issuer] ?? null;
            if (is_string($pinned) && $pinned !== '') {
                if (!hash_equals($pinned, $url)) {
                    $this->logger->warning('JWKS URL does not match issuer pin', [
                        'issuer' => $issuer,
                        'url' => $url,
                        'pinned' => $pinned,
                    ]);
                    return false;
                }
            }
        }

        return true;
    }

    private function cachePathForUrl(string $url, ?string $cacheDir): string
    {
        $dir = $cacheDir ? rtrim($cacheDir, "/\\") : rtrim(sys_get_temp_dir(), "/\\");
        if ($dir === '') $dir = sys_get_temp_dir();

        $hash = hash('sha256', $url);
        return $dir . DIRECTORY_SEPARATOR . 'winglet_jwks_' . $hash . '.json';
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readCache(string $path): ?array
    {
        if (!is_file($path)) return null;
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') return null;

        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function writeCache(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) return;

        $temporary = @tempnam($dir, '.winglet_jwks_');
        if (!is_string($temporary)) return;

        try {
            if (@file_put_contents($temporary, $json, LOCK_EX) === false) {
                return;
            }
            @chmod($temporary, 0600);
            @rename($temporary, $path);
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * @return array<string,mixed> JWKS decoded array
     */
    private function fetchJwks(string $url, int $timeoutSeconds): array
    {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => max(1, $timeoutSeconds),
                'header' => "Accept: application/json\r\n",
            ],
        ]);

        $raw = @file_get_contents($url, false, $ctx);
        if (!is_string($raw) || $raw === '') {
            throw new \RuntimeException('Empty JWKS response');
        }

        $jwks = json_decode($raw, true);
        if (!is_array($jwks)) {
            throw new \RuntimeException('Invalid JWKS JSON');
        }

        return $jwks;
    }

    /**
     * Convert JWKS into verifier key entries:
     * - key id => ['alg' => 'RS256', 'public_key_pem' => '...']
     *
     * @param array<string,mixed> $jwks
     * @return array<string,array{alg:string, public_key_pem:string}>
     */
    private function jwksToKeys(array $jwks): array
    {
        $keys = $jwks['keys'] ?? null;
        if (!is_array($keys)) return [];

        $out = [];

        foreach ($keys as $k) {
            if (!is_array($k)) continue;

            $kty = (string)($k['kty'] ?? '');
            $use = (string)($k['use'] ?? 'sig');
            $alg = (string)($k['alg'] ?? 'RS256');
            $kid = (string)($k['kid'] ?? '');

            if ($use !== '' && $use !== 'sig') continue;
            if ($kty !== 'RSA') continue;
            if ($alg !== 'RS256') continue;
            if ($kid === '') continue;

            $pem = $this->publicKeyPemFromJwk($k);
            if ($pem === null) continue;

            $out[$kid] = [
                'alg' => 'RS256',
                'public_key_pem' => $pem,
            ];
        }

        return $out;
    }

    /**
     * Extract public key PEM from RSA JWK via x5c.
     * @param array<string,mixed> $jwk
     */
    private function publicKeyPemFromJwk(array $jwk): ?string
    {
        $x5c = $jwk['x5c'] ?? null;
        if (!is_array($x5c) || !$x5c) {
            $n = $jwk['n'] ?? null;
            $e = $jwk['e'] ?? null;
            if (!is_string($n) || !is_string($e)) return null;
            $modulus = $this->b64urlDecode($n);
            $exponent = $this->b64urlDecode($e);
            if ($modulus === null || $exponent === null) return null;
            return $this->rsaPem($modulus, $exponent);
        }

        $leaf = $x5c[0] ?? null;
        if (!is_string($leaf) || $leaf === '') {
            return null;
        }

        $der = base64_decode($leaf, true);
        if ($der === false) return null;

        $pemCert = "-----BEGIN CERTIFICATE-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END CERTIFICATE-----\n";

        $res = openssl_x509_read($pemCert);
        if ($res === false) {
            return null;
        }

        $pubKey = openssl_pkey_get_public($res);
        if ($pubKey === false) {
            return null;
        }

        $details = openssl_pkey_get_details($pubKey);
        if (!is_array($details) || !isset($details['key']) || !is_string($details['key'])) {
            return null;
        }

        return $details['key'];
    }

    private function b64urlDecode(string $value): ?string
    {
        $value = strtr($value, '-_', '+/');
        $value .= str_repeat('=', (4 - strlen($value) % 4) % 4);
        $decoded = base64_decode($value, true);
        return $decoded === false || $decoded === '' ? null : $decoded;
    }

    private function rsaPem(string $modulus, string $exponent): ?string
    {
        $der = $this->derSequence($this->derInteger($modulus) . $this->derInteger($exponent));
        $bitString = "\x03" . $this->derLength(strlen("\x00" . $der)) . "\x00" . $der;
        $spki = $this->derSequence(
            $this->derSequence("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00") . $bitString
        );
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
        return openssl_pkey_get_public($pem) === false ? null : $pem;
    }

    private function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || (ord($bytes[0]) & 0x80)) $bytes = "\x00" . $bytes;
        return "\x02" . $this->derLength(strlen($bytes)) . $bytes;
    }

    private function derSequence(string $bytes): string { return "\x30" . $this->derLength(strlen($bytes)) . $bytes; }

    private function derLength(int $length): string
    {
        if ($length < 128) return chr($length);
        $bytes = '';
        while ($length > 0) { $bytes = chr($length & 255) . $bytes; $length >>= 8; }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }
}
