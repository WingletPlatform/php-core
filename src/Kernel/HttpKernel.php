<?php
declare(strict_types=1);

namespace Winglet\Core\Kernel;

use Psr\Log\LoggerInterface;
use Throwable;
use Winglet\Core\Http\Middleware\AccessLogMiddleware;
use Winglet\Core\Http\Middleware\AuthMiddleware;
use Winglet\Core\Http\Middleware\CorsMiddleware;
use Winglet\Core\Http\Middleware\RoutingMiddleware;
use Winglet\Core\Http\Middleware\RequestContextMiddleware;
use Winglet\Core\Http\Middleware\TenantMiddleware;
use Winglet\Core\Http\Request;
use Winglet\Core\Http\Response;
use Winglet\Core\Kernel\Bootstrap\Application;
use Winglet\Core\Kernel\Bootstrap\ApplicationBootstrapperInterface;
use Winglet\Core\Kernel\Middleware\ErrorHandlingMiddleware;
use Winglet\Core\Kernel\Middleware\SessionMiddleware;
use Winglet\Core\Kernel\Pipeline\MiddlewarePipeline;
use Winglet\Core\Support\Config;
use Winglet\Core\Tenancy\TenantResolverInterface;

final class HttpKernel
{
    public function __construct(
        private ApplicationBootstrapperInterface $bootstrapper,
        private ?LoggerInterface $fallbackLogger = null,
    ) {}

    public function handle(Request $request): Response
    {
        try {
            $app = $this->bootstrapper->boot();
            $request = $request->withAttribute('app', $app);

            return $this->handleWithApplication($request, $app);
        } catch (Throwable $e) {
            if (isset($app)) {
                try {
                    return $app->exceptionHandler->handle($e);
                } catch (Throwable $handlerFailure) {
                    $this->logFatalError($handlerFailure);
                }
            }

            return $this->fatalResponse($e);
        }
    }

    private function handleWithApplication(Request $request, Application $app): Response
    {
        $pipeline = new MiddlewarePipeline([
            new RequestContextMiddleware($app->config),
            new CorsMiddleware($app->config),
            new ErrorHandlingMiddleware($app->exceptionHandler),
            new AccessLogMiddleware($this->accessLogLevel($app->config), $app->accessLogger),
            new AuthMiddleware($app->tokenService, $app->securityLogger),
            new TenantMiddleware($app->container->get(TenantResolverInterface::class)),
            new SessionMiddleware($app->sessionManager),
            new RoutingMiddleware($app->router, $app->controllerInvoker),
        ]);

        return $pipeline->handle($request);
    }

    private function accessLogLevel(Config $config): string
    {
        $enabled = (bool)$config->get('http.access_log.enabled', true);
        if (!$enabled) {
            return 'none';
        }

        return (string)$config->getFirst([
            'http.access_log.level',
            'logging.access.level',
        ], 'basic');
    }

    public function handleGlobals(): Response
    {
        try {
            $app = $this->bootstrapper->boot();

            $basePathCfg = (string)$app->config->get('http.base_path', '');
            $trustXfp = (bool)$app->config->getFirst([
                'http.trusted_proxies.trust_forwarded_prefix',
                'http.trust_forwarded_prefix',
            ], true);

            $request = Request::fromGlobals($basePathCfg, $trustXfp)
                ->withAttribute('app', $app);

            return $this->handleWithApplication($request, $app);
        } catch (Throwable $e) {
            if (isset($app)) {
                try {
                    return $app->exceptionHandler->handle($e);
                } catch (Throwable $handlerFailure) {
                    $this->logFatalError($handlerFailure);
                }
            }

            return $this->fatalResponse($e);
        }
    }

    private function fatalResponse(Throwable $exception): Response
    {
        $this->logFatalError($exception);

        return Response::json(
            ['error' => 'ServerError', 'message' => 'Internal server error'],
            500
        );
    }

    private function logFatalError(Throwable $exception): void
    {
        if ($this->fallbackLogger !== null) {
            $this->fallbackLogger->critical('Winglet HTTP kernel fatal error', [
                'exception' => $exception,
            ]);
            return;
        }

        error_log(sprintf(
            'Winglet HTTP kernel fatal error: %s in %s:%d',
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine()
        ));
    }

}
