<?php
declare(strict_types=1);

namespace Winglet\Core\Tests\Kernel\Pipeline;

use Winglet\Core\Http\Response;
use Winglet\Core\Kernel\Middleware\MiddlewareInterface;
use Winglet\Core\Kernel\Pipeline\MiddlewarePipeline;
use Winglet\Core\Tests\TestCase;

final class MiddlewarePipelineTest extends TestCase
{
    public function testMiddlewareRunsInOrderAndCanShortCircuit(): void
    {
        $mw1 = new class implements MiddlewareInterface {
            public function process(\Winglet\Core\Http\Request $request, callable $next): Response
            {
                $trace = $request->getAttribute('trace', []);
                $trace[] = 'mw1:before';
                $request = $request->withAttribute('trace', $trace);

                $resp = $next($request);
                $trace = $request->getAttribute('trace', []);
                $trace[] = 'mw1:after';
                // note: Response is immutable enough for the test; we just return original
                return $resp;
            }
        };

        $mw2 = new class implements MiddlewareInterface {
            public function process(\Winglet\Core\Http\Request $request, callable $next): Response
            {
                $trace = $request->getAttribute('trace', []);
                $trace[] = 'mw2';
                // Short-circuit: do not call next
                return Response::json(['trace' => $trace], 200);
            }
        };

        $pipeline = new MiddlewarePipeline([$mw1, $mw2]);
        $req = $this->makeRequest('GET', '/');
        $resp = $pipeline->handle($req);

        $this->assertSame(200, $resp->status);
        $data = json_decode($resp->body, true);
        $this->assertSame(['mw1:before', 'mw2'], $data['trace']);
    }

    public function testPipelineFallbackWhenNoMiddlewareReturnsAResponse(): void
    {
        $mw = new class implements MiddlewareInterface {
            public function process(\Winglet\Core\Http\Request $request, callable $next): Response
            {
                return $next($request);
            }
        };

        $pipeline = new MiddlewarePipeline([$mw]);
        $resp = $pipeline->handle($this->makeRequest('GET', '/'));

        $this->assertSame(500, $resp->status);
        $data = json_decode($resp->body, true);
        $this->assertSame('No handler', $data['error']);
    }
}
