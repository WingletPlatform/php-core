<?php
declare(strict_types=1);

namespace Winglet\Core\Tests\Http\Routing;

use Winglet\Core\Http\Routing\Router;
use Winglet\Core\Tests\TestCase;

final class RouterTest extends TestCase
{
    public function testMatchesStaticRoute(): void
    {
        $mapFile = $this->writeRouteMap([
            'static' => [
                'GET' => [
                    '/health' => ['controller' => 'HealthController', 'action' => 'handle'],
                ],
            ],
            'dynamic' => [],
        ]);

        $router = new Router($mapFile, reloadOnChange: false);
        $req = $this->makeRequest('GET', '/health');

        $m = $router->match($req);
        $this->assertNotNull($m);
        $this->assertSame('HealthController', $m->controller);
        $this->assertSame('handle', $m->action);
        $this->assertSame([], $m->params);
    }

    public function testMatchesDynamicRouteAndExtractsNamedParams(): void
    {
        $mapFile = $this->writeRouteMap([
            'static' => [],
            'dynamic' => [
                'GET' => [
                    [
                        'regex' => '~^/customers/(?P<id>[^/]+)$~',
                        'params' => ['id'],
                        'controller' => 'CustomerController',
                        'action' => 'get',
                    ],
                ],
            ],
        ]);

        $router = new Router($mapFile, reloadOnChange: false);
        $req = $this->makeRequest('GET', '/customers/123');

        $m = $router->match($req);
        $this->assertNotNull($m);
        $this->assertSame('CustomerController', $m->controller);
        $this->assertSame('get', $m->action);
        $this->assertSame(['id' => '123'], $m->params);
    }

    public function testReturnsNullWhenNoRouteMatches(): void
    {
        $mapFile = $this->writeRouteMap(['static' => [], 'dynamic' => []]);
        $router = new Router($mapFile, reloadOnChange: false);

        $m = $router->match($this->makeRequest('GET', '/missing'));
        $this->assertNull($m);
    }

    public function testHttpMethodsAreMatchedIndependently(): void
    {
        $mapFile = $this->writeRouteMap([
            'static' => [
                'GET' => [
                    '/resource' => ['controller' => 'GetController', 'action' => 'handle'],
                ],
                'POST' => [
                    '/resource' => ['controller' => 'PostController', 'action' => 'handle'],
                ],
            ],
            'dynamic' => [],
        ]);

        self::assertSame('GetController', (new Router($mapFile, false))->match($this->makeRequest('GET', '/resource'))?->controller);
        self::assertSame('PostController', (new Router($mapFile, false))->match($this->makeRequest('POST', '/resource'))?->controller);
        self::assertNull((new Router($mapFile, false))->match($this->makeRequest('DELETE', '/resource')));
    }

    public function testStaticRouteTakesPrecedenceOverDynamicRoute(): void
    {
        $mapFile = $this->writeRouteMap([
            'static' => [
                'GET' => [
                    '/customers/current' => ['controller' => 'CurrentCustomerController', 'action' => 'handle'],
                ],
            ],
            'dynamic' => [
                'GET' => [[
                    'regex' => '~^/customers/(?P<id>[^/]+)$~',
                    'params' => ['id'],
                    'controller' => 'CustomerController',
                    'action' => 'get',
                ]],
            ],
        ]);

        $match = (new Router($mapFile, false))->match($this->makeRequest('GET', '/customers/current'));
        self::assertNotNull($match);
        self::assertSame('CurrentCustomerController', $match->controller);
        self::assertSame([], $match->params);
    }

    public function testPreservesAccessMetadata(): void
    {
        $mapFile = $this->writeRouteMap([
            'static' => [
                'GET' => [
                    '/admin' => [
                        'controller' => 'AdminController',
                        'action' => 'handle',
                        'access' => ['permissions' => ['admin.read']],
                    ],
                ],
            ],
            'dynamic' => [],
        ]);

        $match = (new Router($mapFile, false))->match($this->makeRequest('GET', '/admin'));
        self::assertNotNull($match);
        self::assertSame(['permissions' => ['admin.read']], $match->access);
    }

    public function testInvalidOrMissingRouteMapBehavesAsEmptyMap(): void
    {
        $missing = sys_get_temp_dir() . '/winglet_missing_routes_' . bin2hex(random_bytes(4)) . '.php';
        self::assertNull((new Router($missing, false))->match($this->makeRequest('GET', '/anything')));

        $invalid = sys_get_temp_dir() . '/winglet_invalid_routes_' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($invalid, '<?php return "invalid";');
        self::assertNull((new Router($invalid, false))->match($this->makeRequest('GET', '/anything')));
    }

    private function writeRouteMap(array $map): string
    {
        $file = sys_get_temp_dir() . '/winglet_routes_' . bin2hex(random_bytes(4)) . '.php';
        $export = var_export($map, true);
        file_put_contents($file, "<?php\nreturn {$export};\n");
        $this->addToAssertionCount(1); // keep phpstan happy in some setups
        return $file;
    }
}
