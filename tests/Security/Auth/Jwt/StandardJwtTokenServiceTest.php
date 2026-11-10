<?php
declare(strict_types=1);

namespace Winglet\Core\Tests\Security\Auth\Jwt;

use Psr\Log\NullLogger;
use Winglet\Core\Security\Auth\Jwt\JwksProvider;
use Winglet\Core\Security\Auth\Jwt\StandardJwtTokenService;
use Winglet\Core\Security\Auth\Jwt\TokenException;
use Winglet\Core\Support\Config;
use Winglet\Core\Tests\TestCase;

final class StandardJwtTokenServiceTest extends TestCase
{
    public function testParsesAndValidatesHs256TokenAndMapsClaims(): void
    {
        $secret = 'test-secret-123';

        $cfg = $this->config([
            'authentication' => [
                'jwt' => [
                    'standard' => [
                        'algorithms' => ['HS256'],
                        'issuer' => 'unit-test',
                        'audience' => 'api',
                        'leeway_seconds' => 0,
                        'kid_required' => true,
                        'keys' => [
                            'k1' => ['alg' => 'HS256', 'secret' => $secret],
                        ],
                        'jwks' => ['enabled' => false],
                        // keep defaults for claim mapping: idi,sid,slv,jti
                    ],
                ],
            ],
        ]);

        $svc = new StandardJwtTokenService($cfg, new NullLogger(), new JwksProvider($cfg, new NullLogger()));

        $now = time();
        $token = $this->makeHs256Jwt(
            header: ['alg' => 'HS256', 'kid' => 'k1', 'typ' => 'JWT'],
            payload: [
                'iss' => 'unit-test',
                'aud' => 'api',
                'exp' => $now + 300,
                'iat' => $now,
                'idi' => 123,
                'sid' => 456,
                'slv' => 2,
                'jti' => 7,
                'groups' => ['admins', 'dev'],
                'identityType' => 'service',
                'clientId' => 'identity',
            ],
            secret: $secret
        );

        $auth = $svc->parseAndValidate($token);

        $this->assertTrue($auth->isAuthenticated());
        $this->assertSame(123, $auth->getClientId());
        $this->assertSame(123, $auth->getIdentityId());
        $this->assertSame('service', $auth->getIdentityType());
        $this->assertSame('identity', $auth->getTokenClientId());
        $this->assertSame(2, $auth->getSessionLevel());
        $this->assertSame(['admins', 'dev'], $auth->getGroups());
        $this->assertNotNull($auth->getExpiresAt());
    }

    public function testRejectsExpiredToken(): void
    {
        $secret = 'test-secret-123';

        $cfg = $this->config([
            'authentication' => [
                'jwt' => [
                    'standard' => [
                        'algorithms' => ['HS256'],
                        'leeway_seconds' => 0,
                        'kid_required' => false,
                        'keys' => [
                            'k1' => ['alg' => 'HS256', 'secret' => $secret],
                        ],
                        'jwks' => ['enabled' => false],
                    ],
                ],
            ],
        ]);

        $svc = new StandardJwtTokenService($cfg, new NullLogger(), new JwksProvider($cfg, new NullLogger()));

        $now = time();
        $token = $this->makeHs256Jwt(
            header: ['alg' => 'HS256'],
            payload: ['exp' => $now - 1],
            secret: $secret
        );

        $this->expectException(TokenException::class);
        $this->expectExceptionMessage('Token expired');
        $svc->parseAndValidate($token);
    }


    public function testRejectsNonIntegerSessionClaimInsteadOfCastingItToZero(): void
    {
        $secret = 'test-secret-123';

        $cfg = $this->config([
            'authentication' => [
                'jwt' => [
                    'standard' => [
                        'algorithms' => ['HS256'],
                        'leeway_seconds' => 0,
                        'kid_required' => false,
                        'keys' => [
                            'k1' => ['alg' => 'HS256', 'secret' => $secret],
                        ],
                        'jwks' => ['enabled' => false],
                    ],
                ],
            ],
        ]);

        $svc = new StandardJwtTokenService($cfg, new NullLogger(), new JwksProvider($cfg, new NullLogger()));
        $now = time();
        $token = $this->makeHs256Jwt(
            header: ['alg' => 'HS256'],
            payload: [
                'exp' => $now + 300,
                'sid' => 'session-abc',
            ],
            secret: $secret
        );

        $this->expectException(TokenException::class);
        $this->expectExceptionMessage('Invalid integer claim: sid');
        $svc->parseAndValidate($token);
    }

    public function testRejectsMalformedToken(): void
    {
        $service = $this->service($this->standardConfig());

        $this->expectException(TokenException::class);
        $this->expectExceptionMessage('Token must have 3 parts');
        $service->parseAndValidate('not-a.jwt');
    }

    public function testRejectsUnsupportedAlgorithmBeforeSignatureVerification(): void
    {
        $secret = 'test-secret-123';
        $service = $this->service($this->standardConfig(secret: $secret));
        $token = $this->makeHs256Jwt(
            ['alg' => 'HS512'],
            ['exp' => time() + 300],
            $secret,
        );

        $this->expectException(TokenException::class);
        $this->expectExceptionMessage('Unsupported token algorithm');
        $service->parseAndValidate($token);
    }

    public function testRejectsInvalidSignature(): void
    {
        $service = $this->service($this->standardConfig(secret: 'correct-secret'));
        $token = $this->makeHs256Jwt(
            ['alg' => 'HS256'],
            ['exp' => time() + 300],
            'wrong-secret',
        );

        $this->expectException(TokenException::class);
        $this->expectExceptionMessage('Signature mismatch');
        $service->parseAndValidate($token);
    }

    public function testRejectsMissingExpiration(): void
    {
        $secret = 'test-secret-123';
        $service = $this->service($this->standardConfig(secret: $secret));
        $token = $this->makeHs256Jwt(['alg' => 'HS256'], [], $secret);

        $this->expectException(TokenException::class);
        $this->expectExceptionMessage('Missing/invalid exp');
        $service->parseAndValidate($token);
    }

    public function testRejectsTokenThatIsNotActiveYet(): void
    {
        $secret = 'test-secret-123';
        $service = $this->service($this->standardConfig(secret: $secret));
        $token = $this->makeHs256Jwt(
            ['alg' => 'HS256'],
            ['exp' => time() + 600, 'nbf' => time() + 300],
            $secret,
        );

        $this->expectException(TokenException::class);
        $this->expectExceptionMessage('Token not active yet');
        $service->parseAndValidate($token);
    }

    public function testRejectsWrongIssuer(): void
    {
        $secret = 'test-secret-123';
        $service = $this->service($this->standardConfig(
            secret: $secret,
            issuer: 'expected-issuer',
        ));
        $token = $this->makeHs256Jwt(
            ['alg' => 'HS256'],
            ['exp' => time() + 300, 'iss' => 'other-issuer'],
            $secret,
        );

        $this->expectException(TokenException::class);
        $this->expectExceptionMessage('Invalid issuer');
        $service->parseAndValidate($token);
    }

    public function testRejectsWrongAudience(): void
    {
        $secret = 'test-secret-123';
        $service = $this->service($this->standardConfig(
            secret: $secret,
            audience: 'expected-api',
        ));
        $token = $this->makeHs256Jwt(
            ['alg' => 'HS256'],
            ['exp' => time() + 300, 'aud' => ['other-api']],
            $secret,
        );

        $this->expectException(TokenException::class);
        $this->expectExceptionMessage('Invalid audience');
        $service->parseAndValidate($token);
    }

    public function testAcceptsCanonicalNumericStringClaimsWithoutLosingSessionIdentity(): void
    {
        $secret = 'test-secret-123';
        $service = $this->service($this->standardConfig(secret: $secret));
        $token = $this->makeHs256Jwt(
            ['alg' => 'HS256'],
            [
                'exp' => time() + 300,
                'idi' => '123',
                'sid' => '456',
                'slv' => '2',
                'jti' => '7',
            ],
            $secret,
        );

        $auth = $service->parseAndValidate($token);

        self::assertSame(123, $auth->getIdentityId());
        self::assertSame(456, $auth->getSessionId());
        self::assertSame('456', $auth->getSessionSubject());
        self::assertSame(2, $auth->getSessionLevel());
    }

    public function testLegacyStandardProfileIgnoresRolesAndDiscardsMalformedPrivileges(): void
    {
        $secret = 'test-secret-123';
        $service = $this->service($this->standardConfig(secret: $secret));
        $token = $this->makeHs256Jwt(['alg' => 'HS256'], [
            'exp' => time() + 300,
            'slv' => 2,
            'privileges' => ['reports.read', false],
            'roles' => ['admin'],
        ], $secret);

        $auth = $service->parseAndValidate($token);

        self::assertSame([], $auth->getPrivileges());
        self::assertNull($auth->getRoles());
        self::assertFalse($auth->hasPrivilege('reports.read'));
    }

    public function testZeroSessionLevelIsAnonymous(): void
    {
        $secret = 'test-secret-123';
        $service = $this->service($this->standardConfig(secret: $secret));
        $token = $this->makeHs256Jwt(['alg' => 'HS256'], [
            'exp' => time() + 300,
            'slv' => 0,
            'idi' => 42,
            'privileges' => ['reports.read'],
        ], $secret);

        $auth = $service->parseAndValidate($token);

        self::assertFalse($auth->isAuthenticated());
        self::assertSame([], $auth->getPrivileges());
    }

    private function service(Config $config): StandardJwtTokenService
    {
        $logger = new NullLogger();
        return new StandardJwtTokenService($config, $logger, new JwksProvider($config, $logger));
    }

    private function standardConfig(
        string $secret = 'test-secret-123',
        ?string $issuer = null,
        ?string $audience = null,
    ): Config {
        return $this->config([
            'authentication' => [
                'jwt' => [
                    'standard' => [
                        'algorithms' => ['HS256'],
                        'issuer' => $issuer,
                        'audience' => $audience,
                        'leeway_seconds' => 0,
                        'kid_required' => false,
                        'keys' => [
                            'k1' => ['alg' => 'HS256', 'secret' => $secret],
                        ],
                        'jwks' => ['enabled' => false],
                    ],
                ],
            ],
        ]);
    }

    private function config(array $data): Config
    {
        // Config has a private constructor; we can round-trip via a temp php file.
        $file = sys_get_temp_dir() . '/winglet_cfg_' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, "<?php\nreturn " . var_export($data, true) . ";\n");
        $cfg = Config::fromPhpFile($file);
        @unlink($file);
        return $cfg;
    }

    /**
     * @param array<string,mixed> $header
     * @param array<string,mixed> $payload
     */
    private function makeHs256Jwt(array $header, array $payload, string $secret): string
    {
        $h64 = $this->b64url(json_encode($header, JSON_UNESCAPED_SLASHES));
        $p64 = $this->b64url(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signingInput = $h64 . '.' . $p64;
        $sig = hash_hmac('sha256', $signingInput, $secret, true);
        $s64 = $this->b64url($sig);
        return $signingInput . '.' . $s64;
    }

    private function b64url(string $raw): string
    {
        $b64 = base64_encode($raw);
        return rtrim(strtr($b64, '+/', '-_'), '=');
    }
}
