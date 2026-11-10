<?php
declare(strict_types=1);

namespace Winglet\Core\Tests\Security\Auth\Jwt;

use OpenSSLAsymmetricKey;
use Psr\Log\NullLogger;
use Winglet\Core\Security\Auth\Jwt\JwksProvider;
use Winglet\Core\Security\Auth\Jwt\StandardJwtTokenService;
use Winglet\Core\Security\Auth\Jwt\TokenException;
use Winglet\Core\Support\Config;
use Winglet\Core\Tests\TestCase;

/**
 * Consumer fixture for Core's documented canonical JWT profile.
 * This is not a fixture exported by the PHP Identity service.
 */
final class CanonicalJwtConsumerFixtureTest extends TestCase
{
    public function testCanonicalUserClaimsAreProjectedWithoutRoleOrGroupAssumptions(): void
    {
        [$privateKey, $publicPem] = $this->keyPair();
        $service = $this->service($publicPem);
        $now = time();
        $token = $this->makeJwt($privateKey, [
            'iss' => 'https://identity.example.test',
            'iat' => $now,
            'exp' => $now + 300,
            'sub' => 'user-42',
            'identity_type' => 'user',
            'acr' => 'urn:winglet:acr:authenticated',
            'auth_time' => $now - 30,
            'sid' => '987',
            'session_version' => 3,
            'token_context' => 'global',
            'privileges' => ['credits.read'],
        ]);

        $auth = $service->parseAndValidate($token);

        self::assertTrue($auth->isAuthenticated());
        self::assertSame('canonical_v1', $auth->getTokenProfile());
        self::assertSame('user-42', $auth->getActorSubject());
        self::assertNull($auth->getCallerServiceSubject());
        self::assertSame(987, $auth->getSessionId());
        self::assertSame(3, $auth->getSessionVersion());
        self::assertSame('global', $auth->getTenantContext());
        self::assertSame('Authenticated', $auth->getAssurance());
        self::assertSame(['credits.read'], $auth->getPrivileges());
        self::assertTrue($auth->hasPrivilege('credits.read'));
        self::assertFalse($auth->hasPrivilege('credits.write'));
        self::assertSame([], $auth->getGroups());
        self::assertNull($auth->getRoles());
    }

    public function testCanonicalProfileRejectsExpiredToken(): void
    {
        [$privateKey, $publicPem] = $this->keyPair();
        $service = $this->service($publicPem);
        $now = time();
        $token = $this->makeJwt($privateKey, [
            'iss' => 'https://identity.example.test',
            'iat' => $now - 600,
            'exp' => $now - 120,
            'sub' => 'user-42',
            'identity_type' => 'user',
            'acr' => 'urn:winglet:acr:authenticated',
            'auth_time' => $now - 900,
            'sid' => '987',
            'session_version' => 3,
            'token_context' => 'global',
            'privileges' => [],
        ]);

        $this->expectException(TokenException::class);
        $this->expectExceptionMessage('Token expired');
        $service->parseAndValidate($token);
    }

    public function testCanonicalMalformedPrivilegesGrantNothing(): void
    {
        [$privateKey, $publicPem] = $this->keyPair();
        $service = $this->service($publicPem);
        $now = time();
        $token = $this->makeJwt($privateKey, [
            'iss' => 'https://identity.example.test',
            'iat' => $now,
            'exp' => $now + 300,
            'sub' => 'user-42',
            'identity_type' => 'user',
            'acr' => 'urn:winglet:acr:authenticated',
            'auth_time' => $now,
            'sid' => '987',
            'session_version' => 3,
            'token_context' => 'global',
            'privileges' => ['credits.read', 7],
        ]);

        $auth = $service->parseAndValidate($token);

        self::assertSame([], $auth->getPrivileges());
        self::assertFalse($auth->hasPrivilege('credits.read'));
    }

    public function testCanonicalTenantContextRequiresPositiveTenantAndGlobalContextOmitsIt(): void
    {
        [$privateKey, $publicPem] = $this->keyPair();
        $service = $this->service($publicPem);
        $now = time();
        $tenantClaims = [
            'iss' => 'https://identity.example.test',
            'iat' => $now,
            'exp' => $now + 300,
            'sub' => 'user-42',
            'identity_type' => 'user',
            'acr' => 'urn:winglet:acr:authenticated',
            'auth_time' => $now,
            'sid' => '987',
            'session_version' => 3,
            'token_context' => 'tenant',
            'tenant_id' => 12,
            'privileges' => ['credits.read'],
        ];

        $tenant = $service->parseAndValidate($this->makeJwt($privateKey, $tenantClaims));
        self::assertSame('tenant', $tenant->getTenantContext());
        self::assertSame(12, $tenant->getTenantId());

        $tenantClaims['token_context'] = 'global';
        $this->expectException(TokenException::class);
        $this->expectExceptionMessage('Invalid tenant scope');
        $service->parseAndValidate($this->makeJwt($privateKey, $tenantClaims));
    }

    /** @return array{OpenSSLAsymmetricKey,string} */
    private function keyPair(): array
    {
        $privateKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        if (!$privateKey instanceof OpenSSLAsymmetricKey) {
            self::fail('Unable to generate RSA fixture key.');
        }
        $privatePem = '';
        if (!openssl_pkey_export($privateKey, $privatePem)) {
            self::fail('Unable to export RSA fixture key.');
        }
        $details = openssl_pkey_get_details($privateKey);
        self::assertIsArray($details);
        self::assertIsString($details['key'] ?? null);
        return [$privateKey, $details['key']];
    }

    private function service(string $publicPem): StandardJwtTokenService
    {
        $file = sys_get_temp_dir() . '/winglet_canonical_jwt_' . bin2hex(random_bytes(5)) . '.php';
        file_put_contents($file, "<?php\nreturn " . var_export([
            'authentication' => ['jwt' => ['standard' => [
                'issuer' => 'https://identity.example.test',
                'keys' => ['fixture-key' => ['alg' => 'RS256', 'public_key_pem' => $publicPem]],
                'jwks' => ['enabled' => false],
            ]]],
        ], true) . ";\n");
        try {
            $config = Config::fromPhpFile($file);
        } finally {
            @unlink($file);
        }

        $logger = new NullLogger();
        return new StandardJwtTokenService($config, $logger, new JwksProvider($config, $logger));
    }

    /** @param array<string,mixed> $payload */
    private function makeJwt(OpenSSLAsymmetricKey $privateKey, array $payload): string
    {
        $header = ['alg' => 'RS256', 'kid' => 'fixture-key', 'typ' => 'winglet+jwt'];
        $input = $this->base64Url(json_encode($header, JSON_THROW_ON_ERROR)) . '.'
            . $this->base64Url(json_encode($payload, JSON_THROW_ON_ERROR));
        self::assertTrue(openssl_sign($input, $signature, $privateKey, OPENSSL_ALGO_SHA256));
        return $input . '.' . $this->base64Url($signature);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
