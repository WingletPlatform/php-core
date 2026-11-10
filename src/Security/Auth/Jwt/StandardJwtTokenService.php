<?php
declare(strict_types=1);

namespace Winglet\Core\Security\Auth\Jwt;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Winglet\Core\Auth\AuthContext;
use Winglet\Core\Support\Config;

final class StandardJwtTokenService implements JwtTokenServiceInterface
{
    public function __construct(
        private Config $config,
        private LoggerInterface $logger,
        private JwksProvider $jwksProvider,
    ) {
    }

    public function parseAndValidate(string $token): AuthContext
    {
        $cfg = (array)$this->config->get('authentication.jwt.standard', []);
        [$header, $payload, $signature, $input] = $this->decodeJwt($token);

        if (($header['typ'] ?? null) === 'winglet+jwt') {
            return $this->canonical($header, $payload, $signature, $input, $cfg);
        }

        return $this->oldStandard($header, $payload, $signature, $input, $cfg);
    }

    private function canonical(array $header, array $p, string $sig, string $input, array $cfg): AuthContext
    {
        if (($header['alg'] ?? null) !== 'RS256') {
            throw TokenException::invalidToken('Canonical JWT requires RS256');
        }

        $kid = $header['kid'] ?? null;
        if (!is_string($kid) || $kid === '') {
            throw TokenException::invalidToken('kid is required');
        }

        $issuer = $cfg['canonical']['issuer'] ?? $cfg['issuer'] ?? null;
        if (
            !is_string($issuer)
            || $issuer === ''
            || !is_string($p['iss'] ?? null)
            || !hash_equals($issuer, $p['iss'])
        ) {
            throw TokenException::invalidToken('Invalid issuer');
        }

        $this->verify('RS256', $kid, $cfg, $input, $sig, true);
        $iat = $this->date($p, 'iat');
        $exp = $this->date($p, 'exp');

        if ($exp + 60 < time()) {
            throw TokenException::expired('Token expired');
        }

        if ($iat > time() + 60) {
            throw TokenException::invalidToken('iat is in the future');
        }

        if ($exp < $iat) {
            throw TokenException::invalidToken('Invalid lifetime');
        }

        $type = $p['identity_type'] ?? null;
        if ($type !== null && !is_string($type)) {
            throw TokenException::invalidData('Invalid identity_type');
        }

        $sessionSubject = $p['sid'] ?? null;
        if ($sessionSubject === null && $type !== 'service') {
            throw TokenException::invalidData('Invalid sid');
        }

        if ($sessionSubject !== null && !is_string($sessionSubject)) {
            throw TokenException::invalidData('Invalid sid');
        }

        $sessionSubject = $sessionSubject === null ? null : $this->sessionSubject($sessionSubject);
        $sid = $sessionSubject === null ? null : $this->numericSessionId($sessionSubject);
        $version = $this->integer($p, 'session_version');

        $scope = $p['token_context'] ?? null;
        if (!is_string($scope) || !in_array($scope, ['global', 'tenant'], true)) {
            throw TokenException::invalidData('Invalid token_context');
        }

        $tenant = array_key_exists('tenant_id', $p) ? $this->positive($p, 'tenant_id') : null;
        if (($scope === 'global' && $tenant !== null) || ($scope === 'tenant' && $tenant === null)) {
            throw TokenException::invalidData('Invalid tenant scope');
        }

        if (!isset($p['privileges']) || !is_array($p['privileges'])) {
            throw TokenException::invalidData('Invalid privileges');
        }

        $privileges = [];
        if (array_is_list($p['privileges'])) {
            $validPrivileges = true;
            foreach ($p['privileges'] as $v) {
                if (!is_string($v) || $v === '') {
                    $validPrivileges = false;
                    break;
                }

                $privileges[] = $v;
            }
            if (!$validPrivileges) {
                $privileges = [];
            }
        }

        $sub = $p['sub'] ?? null;
        if ($sub !== null && !is_string($sub)) {
            throw TokenException::invalidData('Invalid sub');
        }

        $acr = $p['acr'] ?? null;
        $authTime = array_key_exists('auth_time', $p) ? $this->date($p, 'auth_time') : null;
        $client = $p['client_id'] ?? null;
        if ($client !== null && (!is_string($client) || $client === '')) {
            throw TokenException::invalidData('Invalid client_id');
        }

        $actor = null;
        $caller = null;
        $assurance = 'Anonymous';

        if ($type === 'service') {
            if ($sub === null || $client === null || $acr !== null || $authTime !== null) {
                throw TokenException::invalidData('Invalid service identity context');
            }

            $caller = $sub;
        } elseif ($type !== null && $type !== 'user') {
            throw TokenException::invalidData('Invalid identity_type');
        } elseif ($sub === null) {
            if ($acr !== null || $authTime !== null) {
                throw TokenException::invalidData('Anonymous token has end-user claims');
            }
        } else {
            if (
                !in_array($acr, ['urn:winglet:acr:authenticated', 'urn:winglet:acr:strong'], true)
                || $authTime === null
            ) {
                throw TokenException::invalidData('Authenticated user assurance is incomplete');
            }

            $actor = $sub;
            $assurance = $acr === 'urn:winglet:acr:strong' ? 'Strong' : 'Authenticated';
        }

        return new AuthContext(
            $sub !== null,
            $this->subjectId($actor ?? $caller),
            $sid,
            $version,
            null,
            [],
            array_values(array_unique($privileges)),
            null,
            (new DateTimeImmutable())->setTimestamp($exp),
            $p,
            actorSubject: $actor,
            callerServiceSubject: $caller,
            sessionVersion: $version,
            tenantId: $tenant,
            tenantContext: $scope,
            assurance: $assurance,
            tokenProfile: 'canonical_v1',
        );
    }

    private function oldStandard(array $header, array $p, string $sig, string $input, array $cfg): AuthContext
    {
        $alg = $header['alg'] ?? null;
        $allowed = $this->strings($cfg['algorithms'] ?? ['HS256']);
        if (!is_string($alg) || !in_array($alg, $allowed, true)) {
            throw TokenException::invalidToken('Unsupported token algorithm');
        }

        $kid = isset($header['kid']) && is_string($header['kid']) ? $header['kid'] : null;
        if (($cfg['kid_required'] ?? false) && !$kid) {
            throw TokenException::invalidToken('kid is required');
        }

        $this->verify($alg, $kid, $cfg, $input, $sig, false);
        $leeway = (int)($cfg['leeway_seconds'] ?? 60);
        $exp = $this->oldDate($p, 'exp');

        if (time() > $exp + $leeway) {
            throw TokenException::expired('Token expired');
        }

        if (isset($p['nbf']) && time() + $leeway < $this->oldDate($p, 'nbf')) {
            throw TokenException::invalidToken('Token not active yet');
        }

        if (isset($p['iat'])) {
            $this->oldDate($p, 'iat');
        }

        if (($cfg['issuer'] ?? null) !== null && (!is_string($p['iss'] ?? null) || $p['iss'] !== $cfg['issuer'])) {
            throw TokenException::invalidToken('Invalid issuer');
        }

        if (($cfg['audience'] ?? null) !== null && !$this->audience($p['aud'] ?? null, (string)$cfg['audience'])) {
            throw TokenException::invalidToken('Invalid audience');
        }

        $m = (array)($cfg['claims'] ?? []);
        $id = $this->nullableInt($p, (string)($m['client_id'] ?? 'idi'));
        $sid = $this->nullableInt($p, (string)($m['session_id'] ?? 'sid'));
        $level = $this->nullableInt($p, (string)($m['session_level'] ?? 'slv'));
        $version = $this->nullableInt($p, (string)($m['identity_version'] ?? 'jti'));

        $groups = $this->strings($p[$m['groups'] ?? 'groups'] ?? ($p[$m['groups_fallback'] ?? 'grp'] ?? []));
        $privileges = $this->flatPrivileges($p[$m['privileges'] ?? 'privileges'] ?? []);

        if ($level === 0) {
            return AuthContext::anonymous();
        }

        return new AuthContext(
            true,
            $id,
            $sid,
            $version,
            $level,
            $groups,
            $privileges,
            null,
            (new DateTimeImmutable())->setTimestamp($exp),
            $p,
            tokenProfile: 'standard_legacy',
        );
    }

    private function verify(string $alg, ?string $kid, array $cfg, string $input, string $sig, bool $canonical): void
    {
        $keys = (array)($cfg['keys'] ?? []);
        $candidates = [];

        foreach ($keys as $k => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $entryKid = is_string($k) ? $k : ($entry['kid'] ?? null);
            if ($kid !== null && $entryKid !== $kid) {
                continue;
            }

            if (($entry['alg'] ?? null) !== $alg) {
                continue;
            }

            $candidates[] = $entry;
        }

        if (!$candidates) {
            foreach ($this->jwksProvider->getVerificationKeys() as $k => $entry) {
                if ($kid !== null && $k !== $kid) {
                    continue;
                }

                if ($entry['alg'] !== $alg) {
                    continue;
                }

                $candidates[] = $entry;
            }
        }

        if (!$candidates && $canonical && $kid !== null) {
            foreach ($this->jwksProvider->getVerificationKeys(true) as $k => $entry) {
                if ($k === $kid) {
                    $candidates[] = $entry;
                }
            }
        }

        foreach ($candidates as $entry) {
            if (
                $alg === 'HS256'
                && isset($entry['secret'])
                && hash_equals(hash_hmac('sha256', $input, (string)$entry['secret'], true), $sig)
            ) {
                return;
            }

            if (
                $alg === 'RS256'
                && isset($entry['public_key_pem'])
                && openssl_verify($input, $sig, (string)$entry['public_key_pem'], OPENSSL_ALGO_SHA256) === 1
            ) {
                return;
            }
        }

        $this->logger->warning('JWT signature verification failed', ['alg' => $alg, 'kid' => $kid]);
        throw TokenException::securityMismatch('Signature mismatch');
    }

    private function decodeJwt(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw TokenException::invalidToken('Token must have 3 parts');
        }

        $h = $this->b64($parts[0]);
        $p = $this->b64($parts[1]);
        $s = $this->b64($parts[2]);
        $header = json_decode($h, true);
        $payload = json_decode($p, true);

        if (!is_array($header) || !is_array($payload)) {
            throw TokenException::invalidData('Invalid JSON in token');
        }

        return [$header, $payload, $s, $parts[0] . '.' . $parts[1]];
    }

    private function b64(string $v): string
    {
        $v = strtr($v, '-_', '+/');
        $v .= str_repeat('=', (4 - strlen($v) % 4) % 4);
        $x = base64_decode($v, true);
        if ($x === false) {
            throw TokenException::invalidData('Invalid base64url');
        }

        return $x;
    }

    private function date(array $p, string $n): int
    {
        $v = $p[$n] ?? null;
        if (!is_int($v) && !(is_float($v) && is_finite($v) && floor($v) === $v)) {
            throw TokenException::invalidData("Missing/invalid {$n}");
        }

        if ($v < 0 || $v > PHP_INT_MAX) {
            throw TokenException::invalidData("Invalid {$n}");
        }

        return (int)$v;
    }

    private function oldDate(array $p, string $n): int
    {
        $v = $p[$n] ?? null;
        if (is_string($v) && preg_match('/^\d+$/D', $v) === 1) {
            $v = (int)$v;
        }

        if (!is_int($v) && !(is_float($v) && is_finite($v) && floor($v) === $v)) {
            throw TokenException::invalidData("Missing/invalid {$n}");
        }

        if ($v < 0 || $v > PHP_INT_MAX) {
            throw TokenException::invalidData("Invalid {$n}");
        }

        return (int)$v;
    }

    private function positive(array $p, string $n): int
    {
        $v = $p[$n] ?? null;
        if (!is_int($v) || $v <= 0) {
            throw TokenException::invalidData("Invalid {$n}");
        }

        return $v;
    }

    private function sessionSubject(string $v): string
    {
        if ($v === '' || strlen($v) > 128) {
            throw TokenException::invalidData('Invalid sid');
        }

        return $v;
    }

    private function numericSessionId(string $v): ?int
    {
        if (preg_match('/^[1-9]\d*$/D', $v) !== 1 || strlen($v) > strlen((string)PHP_INT_MAX)) {
            return null;
        }

        $id = (int)$v;
        return $id > 0 ? $id : null;
    }

    private function integer(array $p, string $n, bool $optional = false): ?int
    {
        if (!array_key_exists($n, $p)) {
            if ($optional) {
                return null;
            }

            throw TokenException::invalidData("Invalid {$n}");
        }

        $v = $p[$n];
        if (is_string($v) && preg_match('/^\d+$/D', $v) === 1 && strlen(ltrim($v, '0')) <= strlen((string)PHP_INT_MAX)) {
            $v = (int)$v;
        }

        if (!is_int($v) || $v < 0) {
            throw TokenException::invalidData("Invalid {$n}");
        }

        return $v;
    }

    private function subjectId(?string $v): ?int
    {
        if ($v === null || preg_match('/^\d+$/D', $v) !== 1) {
            return null;
        }

        return $this->integer(['x' => $v], 'x');
    }

    private function nullableInt(array $p, string $n): ?int
    {
        if (!array_key_exists($n, $p)) {
            return null;
        }

        $v = $p[$n];
        if (is_int($v)) {
            return $v;
        }

        if (is_string($v) && preg_match('/^-?\d+$/D', $v) === 1) {
            return (int)$v;
        }

        throw TokenException::invalidData("Invalid integer claim: {$n}");
    }

    private function strings(mixed $v): array
    {
        if (!is_array($v)) {
            return [];
        }

        $o = [];
        foreach ($v as $x) {
            if (is_string($x) && $x !== '') {
                $o[] = $x;
            }
        }

        return $o;
    }

    /** @return list<string> */
    private function flatPrivileges(mixed $value): array
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

    private function audience(mixed $v, string $expected): bool
    {
        if (is_string($v)) {
            return hash_equals($expected, $v);
        }

        if (is_array($v)) {
            foreach ($v as $x) {
                if (is_string($x) && hash_equals($expected, $x)) {
                    return true;
                }
            }
        }

        return false;
    }
}
