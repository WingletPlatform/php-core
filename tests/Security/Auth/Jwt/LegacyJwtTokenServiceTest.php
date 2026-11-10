<?php
declare(strict_types=1);

namespace Winglet\Core\Tests\Security\Auth\Jwt;

use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Winglet\Core\Security\Auth\Jwt\LegacyJwtTokenService;
use Winglet\Core\Security\Auth\Jwt\TokenException;
use Winglet\Core\Support\Config;
use Winglet\Core\Tests\TestCase;

final class LegacyJwtTokenServiceTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function testParsesValidLegacyTokenAndPreservesClaims(): void
    {
        $secret = 'legacy-secret';
        $service = $this->service($secret);
        $token = $this->token([
            'exp' => date(DATE_ATOM, time() + 600),
            'jti' => '0007',
            'idi' => '42',
            'sid' => '123',
            'slv' => 20,
            'groups' => ['admin', '', 123],
            'privileges' => ['events.manage'],
            'roles' => ['organizer'],
        ], $secret);

        $context = $service->parseAndValidate($token);

        self::assertTrue($context->isAuthenticated());
        self::assertSame(42, $context->getIdentityId());
        self::assertSame(123, $context->getSessionId());
        self::assertSame('123', $context->getSessionSubject());
        self::assertSame(7, $context->getIdentityVersion());
        self::assertSame(20, $context->getSessionLevel());
        self::assertSame(['admin'], $context->getGroups());
        self::assertSame(['events.manage'], $context->getPrivileges());
        self::assertNull($context->getRoles());
    }

    public function testAllowsAnonymousIdentityInAuthenticatedLegacySession(): void
    {
        $secret = 'legacy-secret';
        $context = $this->service($secret)->parseAndValidate($this->token([
            'exp' => date(DATE_ATOM, time() + 600),
            'jti' => 1,
            'idi' => null,
            'sid' => 55,
            'slv' => 0,
        ], $secret));

        self::assertFalse($context->isAuthenticated());
        self::assertNull($context->getIdentityId());
        self::assertNull($context->getSessionId());
    }

    public function testRolesOnlyClaimDoesNotProvideGroupsOrPrivileges(): void
    {
        $secret = 'legacy-secret';
        $payload = $this->validPayload();
        $payload['slv'] = 10;
        $payload['roles'] = ['admin'];
        $context = $this->service($secret)->parseAndValidate($this->token($payload, $secret));

        self::assertSame([], $context->getGroups());
        self::assertSame([], $context->getPrivileges());
        self::assertNull($context->getRoles());
        self::assertFalse($context->hasPrivilege('admin.access'));
    }

    public function testMalformedPrivilegesGrantNothing(): void
    {
        $secret = 'legacy-secret';
        $payload = $this->validPayload();
        $payload['privileges'] = ['reports.read', 42];
        $context = $this->service($secret)->parseAndValidate($this->token($payload, $secret));

        self::assertSame([], $context->getPrivileges());
        self::assertFalse($context->hasPrivilege('reports.read'));
    }

    public function testRejectsInvalidSignature(): void
    {
        $secret = 'legacy-secret';
        $token = $this->token($this->validPayload(), $secret);
        $token = substr($token, 0, -1) . (str_ends_with($token, '0') ? '1' : '0');

        $this->expectException(TokenException::class);
        $this->expectExceptionMessage('Security hash mismatch');
        $this->service($secret)->parseAndValidate($token);
    }

    public function testRejectsExpiredToken(): void
    {
        $secret = 'legacy-secret';
        $payload = $this->validPayload();
        $payload['exp'] = date(DATE_ATOM, time() - 60);

        $this->expectException(TokenException::class);
        $this->expectExceptionMessage('Session expired');
        $this->service($secret)->parseAndValidate($this->token($payload, $secret));
    }

    public function testMalformedExpiryIsMappedToTokenException(): void
    {
        $secret = 'legacy-secret';
        $payload = $this->validPayload();
        $payload['exp'] = 'definitely-not-a-date';

        $this->expectException(TokenException::class);
        $this->expectExceptionMessage('Invalid exp');
        $this->service($secret)->parseAndValidate($this->token($payload, $secret));
    }

    /** @param mixed $value */
    #[DataProvider('invalidIntegerClaims')]
    public function testRejectsNonCanonicalOrUnsafeIntegerClaims(string $claim, mixed $value): void
    {
        $secret = 'legacy-secret';
        $payload = $this->validPayload();
        $payload[$claim] = $value;

        $this->expectException(TokenException::class);
        $this->expectExceptionMessage("Invalid {$claim}");
        $this->service($secret)->parseAndValidate($this->token($payload, $secret));
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function invalidIntegerClaims(): iterable
    {
        yield 'decimal session id' => ['sid', '1.5'];
        yield 'exponent session id' => ['sid', '1e3'];
        yield 'negative session id' => ['sid', '-1'];
        yield 'float session level' => ['slv', 2.5];
        yield 'non numeric identity version' => ['jti', 'version-1'];
        yield 'overflow identity id' => ['idi', (string)PHP_INT_MAX . '0'];
    }

    public function testRejectsMalformedTokenPartsAndPayload(): void
    {
        $service = $this->service('legacy-secret');

        foreach (['missing-parts', 'a.b.c.d', 'a.!invalid!.c'] as $token) {
            try {
                $service->parseAndValidate($token);
                self::fail("Expected token to be rejected: {$token}");
            } catch (TokenException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function service(string $secret): LegacyJwtTokenService
    {
        $configFile = tempnam(sys_get_temp_dir(), 'winglet_legacy_config_');
        self::assertIsString($configFile);
        $this->temporaryFiles[] = $configFile;
        file_put_contents($configFile, '<?php return ' . var_export([
            'environment' => ['Secret' => $secret],
            'authentication' => [
                'jwt' => [
                    'legacy' => ['secret_config_key' => 'environment.Secret'],
                ],
            ],
        ], true) . ';');

        return new LegacyJwtTokenService(Config::fromPhpFile($configFile), new NullLogger());
    }

    /** @return array<string,mixed> */
    private function validPayload(): array
    {
        return [
            'exp' => date(DATE_ATOM, time() + 600),
            'jti' => 1,
            'idi' => 2,
            'sid' => 3,
            'slv' => 4,
        ];
    }

    /** @param array<string,mixed> $payload */
    private function token(array $payload, string $secret): string
    {
        $header = base64_encode((string)json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $body = base64_encode((string)json_encode($payload));
        $signature = hash_hmac('sha256', "{$header}.{$body}", $secret);
        return "{$header}.{$body}.{$signature}";
    }
}
