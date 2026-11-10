<?php
declare(strict_types=1);

namespace Winglet\Core\Http\Middleware;

use Winglet\Core\Http\Controller\ControllerInvoker;
use Winglet\Core\Http\Exception\NotFoundException;
use Winglet\Core\Http\Logging\RequestLogContext;
use Winglet\Core\Http\Request;
use Winglet\Core\Http\Response;
use Winglet\Core\Http\Routing\Router;
use Winglet\Core\Kernel\Middleware\MiddlewareInterface;

final class RoutingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Router $router,
        private ControllerInvoker $invoker,
    ) {}

    public function process(Request $request, callable $next): Response
    {
        $match = $this->router->match($request);

        if ($match === null) {
            $app = $request->getAttribute('app');
            $logger = is_object($app) && property_exists($app, 'logger') ? $app->logger : null;

            if ($logger) {
                $logger->warning('Routing failed', [
                    'errorType' => 'ROUTE_NOT_FOUND',
                    'method' => $request->method,
                    'requested' => [
                        'originalPath' => $request->originalPath,
                        'requestUri' => $request->server['REQUEST_URI'] ?? null,
                    ],
                    'basePath' => [
                        'value' => $request->basePath,
                        'source' => $request->basePathSource,
                    ],
                    'routePath' => $request->path,
                ]);
            }

            throw new NotFoundException('No route matched');
        }

        $logContext = $request->getAttribute('request_log_context');
        if ($logContext instanceof RequestLogContext) {
            $route = $request->method . ' ' . $request->path;
            $logContext->setRoute($route, $match->controller, $match->action);
        }

        return $this->invoker->invoke($match, $request);
    }
}
