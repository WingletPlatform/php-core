<?php
declare(strict_types=1);

namespace Winglet\Core\Tests\Security\Auth;

use Psr\Container\ContainerInterface;
use Winglet\Core\Auth\AuthContext;
use Winglet\Core\Http\Exception\HttpException;
use Winglet\Core\Http\Routing\RouteMatch;
use Winglet\Core\Security\AccessControl\AccessDecisionManager;
use Winglet\Core\Tests\TestCase;

final class AccessDecisionManagerSemanticsTest extends TestCase
{
    public function testConfiguredGroupAlternativesAreOrAndAccessRequirementsAreAnd(): void
    {
        $auth = AuthContext::authenticated('42', 20, ['editor']);
        $manager = new AccessDecisionManager($this->container());
        $request = $this->makeRequest('GET', '/private')->withAttribute('auth', $auth);

        $manager->assertAllowed($request, new RouteMatch('FixtureController', 'show', access: [
            'min' => 10,
            'max' => 30,
            'groups' => ['admin', 'editor'],
            'checker' => null,
        ]));

        try {
            $manager->assertAllowed($request, new RouteMatch('FixtureController', 'show', access: [
                'min' => 10,
                'max' => 19,
                'groups' => ['admin', 'editor'],
                'checker' => null,
            ]));
            self::fail('A failed session-level requirement must deny even when one group matches.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->statusCode);
        }
    }

    public function testRolesOnlyClaimsDoNotSatisfyGroupRequirements(): void
    {
        $auth = AuthContext::authenticated('42', 20, [], ['roles' => ['admin']]);
        $manager = new AccessDecisionManager($this->container());
        $request = $this->makeRequest('GET', '/private')->withAttribute('auth', $auth);

        try {
            $manager->assertAllowed($request, new RouteMatch('FixtureController', 'show', access: [
                'min' => null,
                'max' => null,
                'groups' => ['admin'],
                'checker' => null,
            ]));
            self::fail('A roles-only claim must not satisfy a group requirement.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->statusCode);
        }
    }

    private function container(): ContainerInterface
    {
        return new class implements ContainerInterface {
            public function get(string $id): mixed { throw new \RuntimeException('No checker is expected.'); }
            public function has(string $id): bool { return false; }
        };
    }
}
