<?php
declare(strict_types=1);

namespace Winglet\Core\Tests\Security\Auth\Jwt;

use OpenSSLAsymmetricKey;
use Psr\Log\NullLogger;
use Winglet\Core\Security\Auth\Jwt\DualJwtTokenService;
use Winglet\Core\Security\Auth\Jwt\JwksProvider;
use Winglet\Core\Security\Auth\Jwt\LegacyJwtTokenService;
use Winglet\Core\Security\Auth\Jwt\StandardJwtTokenService;
use Winglet\Core\Security\Auth\Jwt\TokenException;
use Winglet\Core\Support\Config;
use Winglet\Core\Tests\TestCase;

/**
 * Synthetic fixtures for the PHP dual-profile dispatcher. The canonical fixture
 * follows the .NET contract shape but is signed in PHP, not by .NET Identity.
 */
final class DualJwtTokenServiceTest extends TestCase
{
    private const LEGACY_SECRET = 'dual-mode-test-secret';

    /** @var list<string> */
    private array $temporaryFiles = [];
    private OpenSSLAsymmetricKey $privateKey;
    private string $publicPem;

    protected function setUp(): void
    {
        [$this->privateKey, $this->publicPem] = $this->keyPair();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function testDispatchesLegacyAndCanonicalTokensThroughTheirOwnValidators(): void
    {
        $service = $this->service();

        $legacy = $service->parseAndValidate($this->legacyToken());
        $canonical = $service->parseAndValidate($this->canonicalToken());

        self::assertSame('legacy', $legacy->getTokenProfile());
        self::assertSame(42, $legacy->getIdentityId());
        self::assertSame(['records.read'], $legacy->getPrivileges());
        self::assertSame('canonical_v1', $canonical->getTokenProfile());
        self::assertSame('42', $canonical->getActorSubject());
        self::assertTrue($canonical->hasPrivilege('records.read'));
    }

    public function testRoutesOldStandardBase64UrlTokensToTheStandardValidator(): void
    {
        $payload = [
            'iss' => 'https://identity.winglet.test',
            'exp' => time() + 300,
            'idi' => 9,
            'sid' => 12,
            'slv' => 2,
            'jti' => 3,
            'privileges' => ['records.read'],
        ];
        $header = ['typ' => 'JWT', 'alg' => 'HS256', 'kid' => 'dual-hs'];
        $input = $this->base64Url(json_encode($header, JSON_THROW_ON_ERROR)) . '.'
            . $this->base64Url(json_encode($payload, JSON_THROW_ON_ERROR));
        $token = $input . '.' . $this->base64Url(hash_hmac('sha256', $input, self::LEGACY_SECRET, true));

        $auth = $this->service()->parseAndValidate($token);

        self::assertSame('standard_legacy', $auth->getTokenProfile());
        self::assertSame(9, $auth->getIdentityId());
        self::assertSame(['records.read'], $auth->getPrivileges());
    }

    public function testCanonicalHeaderSelectsOnlyCanonicalValidatorWhenSignatureFails(): void
    {
        $parts = explode('.', $this->canonicalToken());
        $parts[2] = $this->base64Url(random_bytes(256));

        $this->expectException(TokenException::class);
        $this->expectExceptionMessage('Signature mismatch');
        $this->service()->parseAndValidate(implode('.', $parts));
    }

    public function testTamperedCanonicalTypeCannotPassTheOldStandardRoute(): void
    {
        $parts = explode('.', $this->canonicalToken());
        $parts[0] = $this->base64Url(json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
            'kid' => 'dual-test',
        ], JSON_THROW_ON_ERROR));

        $this->expectException(TokenException::class);
        $this->service()->parseAndValidate(implode('.', $parts));
    }

    public function testRejectsExpiredCanonicalTokensAndTokensSignedByAnotherKey(): void
    {
        $service = $this->service();
        $expired = $this->canonicalToken(claims: ['exp' => time() - 120]);
        try {
            $service->parseAndValidate($expired);
            self::fail('An expired canonical token was accepted.');
        } catch (TokenException) {
            $this->addToAssertionCount(1);
        }

        [$wrongKey] = $this->keyPair();
        $wronglySigned = $this->canonicalToken(signingKey: $wrongKey);
        try {
            $service->parseAndValidate($wronglySigned);
            self::fail('A canonical token signed by an untrusted key was accepted.');
        } catch (TokenException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testRejectsWrongAlgorithmsAndTypesWithoutTryingTheOtherValidator(): void
    {
        $canonical = $this->canonicalToken(['alg' => 'HS256', 'typ' => 'winglet+jwt', 'kid' => 'dual-test']);
        $legacy = $this->legacyToken([], ['alg' => 'RS256', 'typ' => 'JWT']);

        foreach ([$canonical, $legacy] as $token) {
            try {
                $this->service()->parseAndValidate($token);
                self::fail('A token with a mismatched profile algorithm was accepted.');
            } catch (TokenException) {
                $this->addToAssertionCount(1);
            }
        }

        $wrongType = $this->legacyToken([], ['alg' => 'HS256', 'typ' => 'jwt']);
        $this->expectException(TokenException::class);
        $this->service()->parseAndValidate($wrongType);
    }

    public function testRejectsAmbiguousHeadersAndMalformedOrOversizedTokens(): void
    {
        $service = $this->service();
        $ambiguousHeader = base64_encode('{"alg":"HS256","alg":"RS256","typ":"JWT"}');
        $payload = base64_encode((string)json_encode($this->legacyPayload(), JSON_THROW_ON_ERROR));
        $input = $ambiguousHeader . '.' . $payload;
        $ambiguous = $input . '.' . hash_hmac('sha256', $input, self::LEGACY_SECRET);

        foreach (['not-a-jwt', 'a.b.c.d', $ambiguous, str_repeat('a', 16385)] as $token) {
            try {
                $service->parseAndValidate($token);
                self::fail('Malformed or ambiguous token was accepted.');
            } catch (TokenException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testLegacyTenantClaimsNeverCreateTrustedCanonicalTenantContext(): void
    {
        $payload = $this->legacyPayload();
        $payload['tenant_id'] = 77;
        $payload['token_context'] = 'tenant';
        $auth = $this->service()->parseAndValidate($this->legacyToken($payload));

        self::assertSame('global', $auth->getTenantContext());
        self::assertNull($auth->getTenantId());
        self::assertSame(77, $auth->getRawPayload()['tenant_id']);
    }

    public function testLegacyRolesAndMalformedPrivilegesGrantNothingAndZeroLevelIsAnonymous(): void
    {
        $payload = $this->legacyPayload();
        $payload['roles'] = ['admin'];
        $payload['privileges'] = ['records.read', false];
        $auth = $this->service()->parseAndValidate($this->legacyToken($payload));
        self::assertSame([], $auth->getPrivileges());
        self::assertNull($auth->getRoles());
        self::assertFalse($auth->hasPrivilege('records.read'));

        $payload['slv'] = 0;
        $auth = $this->service()->parseAndValidate($this->legacyToken($payload));
        self::assertFalse($auth->isAuthenticated());
        self::assertFalse($auth->hasPrivilege('records.read'));
    }

    public function testCanonicalTenantAndPrivilegeWildcardContextIsPreserved(): void
    {
        $auth = $this->service()->parseAndValidate($this->canonicalToken(claims: [
            'token_context' => 'tenant',
            'tenant_id' => 77,
            'privileges' => ['records.*'],
        ]));

        self::assertSame('tenant', $auth->getTenantContext());
        self::assertSame(77, $auth->getTenantId());
        self::assertTrue($auth->hasPrivilege('records.read'));
        self::assertFalse($auth->hasPrivilege('billing.read'));
    }

    public function testCanonicalServicePrincipalRemainsSessionless(): void
    {
        $auth = $this->service()->parseAndValidate($this->canonicalToken(claims: [
            'sub' => 'service-one',
            'sid' => null,
            'client_id' => 'service-one',
            'identity_type' => 'service',
            'privileges' => ['jobs.run'],
        ]));

        self::assertNull($auth->getActorSubject());
        self::assertSame('service-one', $auth->getCallerServiceSubject());
        self::assertNull($auth->getSessionSubject());
        self::assertTrue($auth->hasPrivilege('jobs.run'));
    }

    private function service(): DualJwtTokenService
    {
        $config = $this->config($this->publicPem);
        $logger = new NullLogger();
        return new DualJwtTokenService(
            new StandardJwtTokenService($config, $logger, new JwksProvider($config, $logger)),
            new LegacyJwtTokenService($config, $logger),
        );
    }

    /** @return array{OpenSSLAsymmetricKey,string} */
    private function keyPair(): array
    {
        $privateKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        if (!$privateKey instanceof OpenSSLAsymmetricKey) {
            self::fail('Unable to generate RSA fixture key.');
        }
        $details = openssl_pkey_get_details($privateKey);
        self::assertIsArray($details);
        self::assertIsString($details['key'] ?? null);
        return [$privateKey, $details['key']];
    }

    private function config(string $publicPem): Config
    {
        $file = tempnam(sys_get_temp_dir(), 'winglet_dual_jwt_');
        self::assertIsString($file);
        $this->temporaryFiles[] = $file;
        file_put_contents($file, '<?php return ' . var_export([
            'environment' => ['Secret' => self::LEGACY_SECRET],
            'authentication' => ['jwt' => [
                'legacy' => ['secret_config_key' => 'environment.Secret'],
                'standard' => [
                    'issuer' => 'https://identity.winglet.test',
                    'algorithms' => ['HS256', 'RS256'],
                    'keys' => [
                        'dual-test' => ['alg' => 'RS256', 'public_key_pem' => $publicPem],
                        'dual-hs' => ['alg' => 'HS256', 'secret' => self::LEGACY_SECRET],
                    ],
                    'jwks' => ['enabled' => false],
                ],
            ]],
        ], true) . ';');
        return Config::fromPhpFile($file);
    }

    /** @param array<string,mixed> $overrides @param array<string,string>|null $header */
    private function legacyToken(array $overrides = [], ?array $header = null): string
    {
        $header ??= ['typ' => 'JWT', 'alg' => 'HS256'];
        $headerPart = base64_encode((string)json_encode($header, JSON_THROW_ON_ERROR));
        $payloadPart = base64_encode((string)json_encode(array_replace($this->legacyPayload(), $overrides), JSON_THROW_ON_ERROR));
        $input = $headerPart . '.' . $payloadPart;
        return $input . '.' . hash_hmac('sha256', $input, self::LEGACY_SECRET);
    }

    /** @return array<string,mixed> */
    private function legacyPayload(): array
    {
        return [
            'exp' => date(DATE_ATOM, time() + 300),
            'jti' => 4,
            'idi' => 42,
            'sid' => 123,
            'slv' => 10,
            'privileges' => ['records.read'],
        ];
    }

    /** @param array<string,string>|null $header @param array<string,mixed> $claims */
    private function canonicalToken(?array $header = null, array $claims = [], ?OpenSSLAsymmetricKey $signingKey = null): string
    {
        $header ??= ['alg' => 'RS256', 'typ' => 'winglet+jwt', 'kid' => 'dual-test'];
        $now = time();
        $payload = array_replace([
            'iss' => 'https://identity.winglet.test',
            'iat' => $now,
            'exp' => $now + 300,
            'sub' => '42',
            'sid' => 'session-guid-01',
            'session_version' => 1,
            'token_context' => 'global',
            'acr' => 'urn:winglet:acr:authenticated',
            'auth_time' => $now,
            'privileges' => ['records.read'],
        ], $claims);
        if (($payload['identity_type'] ?? null) === 'service') {
            unset($payload['acr'], $payload['auth_time']);
        }
        $input = $this->base64Url(json_encode($header, JSON_THROW_ON_ERROR)) . '.'
            . $this->base64Url(json_encode($payload, JSON_THROW_ON_ERROR));
        self::assertTrue(openssl_sign($input, $signature, $signingKey ?? $this->privateKey, OPENSSL_ALGO_SHA256));
        return $input . '.' . $this->base64Url($signature);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
