<?php
declare(strict_types=1);
namespace Winglet\Core\Tests\Unit\Security;
use PHPUnit\Framework\TestCase;
use Winglet\Core\Auth\AuthContext;
use Winglet\Core\Http\Request;
use Winglet\Core\Security\Auth\Session\SessionManager;
final class SessionManagerTest extends TestCase
{
    public function testAnonymousContextDoesNotCreateSession(): void
    {
        self::assertNull((new SessionManager())->resolveFromAuthContext(AuthContext::anonymous()));
    }
    public function testValidatedJwtSessionIsProjectedForCompatibility(): void
    {
        $auth = new AuthContext(true, 42, 99, 1, 10, [], [], [], null, []);
        $session = (new SessionManager())->resolveFromAuthContext($auth);
        self::assertNotNull($session);
        self::assertSame('99', $session->sessionId);
        self::assertSame(42, $session->userId);
    }
    public function testUntrustedSessionHeaderIsIgnored(): void
    {
        $request = $this->request(['X-Session-Id' => 'forged']);
        self::assertNull((new SessionManager())->resolveFromRequest($request));
    }
    /** @param array<string,string> $headers */
    private function request(array $headers): Request
    {
        $reflection = new \ReflectionClass(Request::class);
        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor);
        /** @var Request $request */
        $request = $reflection->newInstanceWithoutConstructor();
        $constructor->invoke($request, 'GET', '/', '/', '', 'none', $headers, [], [], null, '', [], [], [], []);
        return $request;
    }
}
