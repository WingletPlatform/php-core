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
 * Synthetic source-shaped fixture for Winglet.NET Identity's actor token.
 * It mirrors the inspected producer claims; it is not a token emitted by .NET.
 */
final class WingletNetIdentityCanonicalFixtureTest extends TestCase
{
    public function testCanonicalIdentityActorTokenAcceptsOpaqueGuidSessionId(): void
    {
        [$privateKey, $publicPem] = $this->keyPair();
        $service = $this->service($publicPem);
        $now = time();
        $sid = '0199f0a0-4d42-7abc-8def-0123456789ab';
        $token = $this->makeJwt($privateKey, [
            'iss' => 'https://identity.winglet.test',
            'iat' => $now,
            'exp' => $now + 300,
            'sub' => '42',
            'sid' => $sid,
            'session_version' => 1,
            'token_context' => 'global',
            'acr' => 'urn:winglet:acr:authenticated',
            'auth_time' => $now,
            'privileges' => ['identity.party.link', 'credits.read'],
        ]);

        $auth = $service->parseAndValidate($token);

        self::assertSame('canonical_v1', $auth->getTokenProfile());
        self::assertSame('42', $auth->getActorSubject());
        self::assertNull($auth->getSessionId(), 'The legacy numeric accessor must not invent a GUID-to-integer mapping.');
        self::assertSame($sid, $auth->getSessionSubject());
        self::assertSame(1, $auth->getSessionVersion());
        self::assertSame(['identity.party.link', 'credits.read'], $auth->getPrivileges());
        self::assertTrue($auth->hasPrivilege('credits.read'));
        self::assertSame('Authenticated', $auth->getAssurance());
    }

    public function testCanonicalIdentityTokenRejectsInvalidSignature(): void
    {
        [$privateKey, $publicPem] = $this->keyPair();
        $service = $this->service($publicPem);
        $token = $this->makeJwt($privateKey, [
            'iss' => 'https://identity.winglet.test',
            'iat' => time(),
            'exp' => time() + 300,
            'sub' => '42',
            'sid' => '0199f0a0-4d42-7abc-8def-0123456789ab',
            'session_version' => 1,
            'token_context' => 'global',
            'acr' => 'urn:winglet:acr:authenticated',
            'auth_time' => time(),
            'privileges' => [],
        ]);
        $parts = explode('.', $token);
        $parts[2] = $this->base64Url(random_bytes(256));

        $this->expectException(TokenException::class);
        $service->parseAndValidate(implode('.', $parts));
    }

    public function testCanonicalIdentityServiceTokenCanOmitSessionId(): void
    {
        [$privateKey, $publicPem] = $this->keyPair();
        $service = $this->service($publicPem);
        $now = time();
        $token = $this->makeJwt($privateKey, [
            'iss' => 'https://identity.winglet.test',
            'iat' => $now,
            'exp' => $now + 300,
            'sub' => 'winglet.identity',
            'client_id' => 'winglet.identity',
            'identity_type' => 'service',
            'subject_kind' => 'caller',
            'session_version' => 1,
            'token_context' => 'global',
            'privileges' => ['identity.service_principals.manage'],
        ]);

        $auth = $service->parseAndValidate($token);

        self::assertSame('winglet.identity', $auth->getCallerServiceSubject());
        self::assertNull($auth->getSessionId());
        self::assertNull($auth->getSessionSubject());
        self::assertTrue($auth->hasPrivilege('identity.service_principals.manage'));
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
        $file = sys_get_temp_dir() . '/winglet_identity_canonical_jwt_' . bin2hex(random_bytes(5)) . '.php';
        file_put_contents($file, "<?php\nreturn " . var_export([
            'authentication' => ['jwt' => ['standard' => [
                'issuer' => 'https://identity.winglet.test',
                'keys' => ['identity-key' => ['alg' => 'RS256', 'public_key_pem' => $publicPem]],
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
        $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'winglet+jwt', 'kid' => 'identity-key'], JSON_THROW_ON_ERROR));
        $claims = $this->base64Url(json_encode($payload, JSON_THROW_ON_ERROR));
        $input = $header . '.' . $claims;
        self::assertTrue(openssl_sign($input, $signature, $privateKey, OPENSSL_ALGO_SHA256));
        return $input . '.' . $this->base64Url($signature);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
