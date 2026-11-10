<?php
declare(strict_types=1);

namespace Winglet\Core\Tests\Security\Auth\Jwt;

use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Winglet\Core\Http\Exception\HttpException;
use Winglet\Core\Http\Request;
use Winglet\Core\Http\Routing\RouteMatch;
use Winglet\Core\Security\AccessControl\AccessDecisionManager;
use Winglet\Core\Security\Auth\Jwt\LegacyJwtTokenService;
use Winglet\Core\Support\Config;
use Winglet\Core\Tests\TestCase;

/**
 * Fixture mirrors Applications/Identity/Entities/Sessions/Session::getToken()
 * and createTokenPayload() from Wing Identity 2024.1 (revision d821214).
 * It deliberately contains no production key or live session data.
 */
final class WingIdentityLegacyTokenFixtureTest extends TestCase
{
    private const TEST_SECRET = 'identity-fixture-only-secret';

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function testWingIdentityTokenMapsSessionClaimsButHasNoRoleOrPrivilegeGrants(): void
    {
        $payload = $this->identityPayload();
        $token = $this->identityToken($payload);
        $auth = $this->service()->parseAndValidate($token);

        self::assertTrue($auth->isAuthenticated());
        self::assertSame(42, $auth->getIdentityId());
        self::assertSame(321, $auth->getSessionId());
        self::assertSame(321100400, $auth->getIdentityVersion());
        self::assertSame(100, $auth->getSessionLevel());
        self::assertSame([], $auth->getPrivileges());
        self::assertNull($auth->getRoles());
        self::assertSame([], $auth->getGroups());
        self::assertSame($payload, $auth->getRawPayload());
        self::assertFalse($auth->hasPrivilege('credits.read'));
    }

    public function testIdentitySessionLevelCanAuthorizeCoreLevelRouteButRoleRouteIsDenied(): void
    {
        $auth = $this->service()->parseAndValidate($this->identityToken($this->identityPayload()));
        $manager = new AccessDecisionManager(new class implements ContainerInterface {
            public function get(string $id): mixed { throw new \RuntimeException('No checker is expected.'); }
            public function has(string $id): bool { return false; }
        });
        $request = $this->makeRequest('GET', '/identity/private')->withAttribute('auth', $auth);

        $manager->assertAllowed($request, new RouteMatch('FixtureController', 'show', access:[
            'min' => 100, 'max' => null, 'groups' => [], 'checker' => null,
        ]));

        try {
            $manager->assertAllowed($request, new RouteMatch('FixtureController', 'admin', access:[
                'min' => null, 'max' => null, 'groups' => ['identity-admin'], 'checker' => null,
            ]));
            self::fail('Identity does not emit a role/group claim for this route.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->statusCode);
        }
    }

    public function testExpiredAndTamperedIdentityTokensAreRejected(): void
    {
        $expired = $this->identityPayload();
        $expired['exp'] = date('Y-m-d H:i:s', time() - 60);

        try {
            $this->service()->parseAndValidate($this->identityToken($expired));
            self::fail('Expired Identity token should be rejected.');
        } catch (\Winglet\Core\Security\Auth\Jwt\TokenException $exception) {
            self::assertSame('EXPIRED', $exception->codeKey);
        }

        $token = $this->identityToken($this->identityPayload());
        $tampered = substr($token, 0, -1) . (str_ends_with($token, '0') ? '1' : '0');
        try {
            $this->service()->parseAndValidate($tampered);
            self::fail('Tampered Identity token should be rejected.');
        } catch (\Winglet\Core\Security\Auth\Jwt\TokenException $exception) {
            self::assertSame('SECURITY', $exception->codeKey);
        }
    }

    private function service(): LegacyJwtTokenService
    {
        $file = tempnam(sys_get_temp_dir(), 'wing_identity_legacy_fixture_');
        self::assertIsString($file);
        $this->temporaryFiles[] = $file;
        file_put_contents($file, '<?php return ' . var_export([
            'environment' => ['Secret' => self::TEST_SECRET],
            'authentication' => ['jwt' => ['legacy' => ['secret_config_key' => 'environment.Secret']]],
        ], true) . ';');

        return new LegacyJwtTokenService(Config::fromPhpFile($file), new NullLogger());
    }

    /** @return array{exp:string,jti:string,sid:int,slv:int,idi:int,privileges:null} */
    private function identityPayload(): array
    {
        $sessionId = 321;
        $version = 4;
        return [
            'exp' => date('Y-m-d H:i:s', time() + 600),
            'jti' => $sessionId . (1000 + $version) . '00',
            'sid' => $sessionId,
            'slv' => 100,
            'idi' => 42,
            'privileges' => null,
        ];
    }

    /** @param array<string,mixed> $payload */
    private function identityToken(array $payload): string
    {
        $header = base64_encode((string)json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $body = base64_encode((string)json_encode($payload, JSON_THROW_ON_ERROR));
        $input = $header . '.' . $body;
        return $input . '.' . hash_hmac('sha256', $input, self::TEST_SECRET);
    }
}
