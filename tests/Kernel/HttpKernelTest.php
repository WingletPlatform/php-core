<?php
declare(strict_types=1);

namespace Winglet\Core\Tests\Kernel;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Winglet\Core\Auth\AuthContext;
use Winglet\Core\Container\ServiceContainer;
use Winglet\Core\Database\DatabaseManager;
use Winglet\Core\Doctrine\DoctrineManager;
use Winglet\Core\Http\Controller\ControllerInvoker;
use Winglet\Core\Http\Exception\ExceptionHandler;
use Winglet\Core\Http\Request;
use Winglet\Core\Http\Response;
use Winglet\Core\Http\Routing\Router;
use Winglet\Core\Kernel\Bootstrap\Application;
use Winglet\Core\Kernel\Bootstrap\ApplicationBootstrapperInterface;
use Winglet\Core\Kernel\HttpKernel;
use Winglet\Core\Security\AccessControl\AccessDecisionManager;
use Winglet\Core\Security\Auth\Jwt\JwtTokenServiceInterface;
use Winglet\Core\Security\Auth\Session\SessionManager;
use Winglet\Core\Support\Config;
use Winglet\Core\Support\Env;
use Winglet\Core\Tenancy\DefaultTenantResolver;
use Winglet\Core\Tenancy\TenantResolverInterface;
use Winglet\Core\Tests\Support\ArrayLogger;
use Winglet\Core\Tests\TestCase;

final class HttpKernelTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $serverBackup = [];

    protected function tearDown(): void
    {
        foreach (['REQUEST_METHOD', 'REQUEST_URI', 'SCRIPT_NAME'] as $key) {
            if (array_key_exists($key, $this->serverBackup)) {
                $_SERVER[$key] = $this->serverBackup[$key];
            } else {
                unset($_SERVER[$key]);
            }
        }
        parent::tearDown();
    }

    public function testHandleBootsOnceAndRunsCompleteRequestPipeline(): void
    {
        $application = $this->application([
            'GET' => ['/kernel-ok' => ['controller' => KernelOkController::class, 'action' => 'handleGet']],
        ]);
        $bootstrapper = new StubBootstrapper($application);

        $response = (new HttpKernel($bootstrapper))->handle($this->makeRequest('GET', '/kernel-ok'));
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $bootstrapper->calls);
        self::assertSame(200, $response->status);
        self::assertSame(['ok' => true, 'anonymous' => true], $body);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $response->headers['X-Request-Id']);
        self::assertSame($response->headers['X-Request-Id'], $response->headers['X-Correlation-Id']);
    }

    public function testErrorResponseStillContainsRequestAndCorrelationHeaders(): void
    {
        $application = $this->application([
            'GET' => ['/kernel-failure' => ['controller' => KernelFailureController::class, 'action' => 'handleGet']],
        ]);
        $bootstrapper = new StubBootstrapper($application);

        $response = (new HttpKernel($bootstrapper))->handle(
            $this->makeRequest('GET', '/kernel-failure', ['X-Correlation-Id' => 'flow-999'])
        );
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $bootstrapper->calls);
        self::assertSame(500, $response->status);
        self::assertSame('Internal server error', $body['message']);
        self::assertSame('flow-999', $body['correlationId']);
        self::assertSame('flow-999', $response->headers['X-Correlation-Id']);
        self::assertArrayHasKey('X-Request-Id', $response->headers);
        self::assertStringNotContainsString('database password', $response->body);
    }

    public function testAllowedOriginReceivesCorsHeadersOnHandledErrorResponse(): void
    {
        $application = $this->application(
            ['GET' => ['/kernel-failure' => ['controller' => KernelFailureController::class, 'action' => 'handleGet']]],
            ['enabled' => true, 'allow_origin' => ['https://app.example']]
        );

        $response = (new HttpKernel(new StubBootstrapper($application)))->handle(
            $this->makeRequest('GET', '/kernel-failure', ['Origin' => 'https://app.example'])
        );

        self::assertSame(500, $response->status);
        self::assertSame('https://app.example', $response->headers['Access-Control-Allow-Origin']);
        self::assertSame('Origin', $response->headers['Vary']);
        self::assertStringNotContainsString('database password', $response->body);
    }

    public function testHandleGlobalsBootsOnlyOnce(): void
    {
        $application = $this->application([
            'GET' => ['/kernel-ok' => ['controller' => KernelOkController::class, 'action' => 'handleGet']],
        ]);
        $bootstrapper = new StubBootstrapper($application);
        $this->backupServer();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/kernel-ok';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $response = (new HttpKernel($bootstrapper))->handleGlobals();

        self::assertSame(1, $bootstrapper->calls);
        self::assertSame(200, $response->status);
    }

    public function testBootstrapFailureReturnsSafeFatalResponse(): void
    {
        $bootstrapper = new StubBootstrapper(exception: new RuntimeException('secret database password'));
        $fallbackLogger = new ArrayLogger();

        $response = (new HttpKernel($bootstrapper, $fallbackLogger))->handle($this->makeRequest('GET', '/'));
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $bootstrapper->calls);
        self::assertSame(500, $response->status);
        self::assertSame(['error' => 'ServerError', 'message' => 'Internal server error'], $body);
        self::assertStringNotContainsString('secret', $response->body);
        self::assertSame('critical', $fallbackLogger->lastRecord()['level']);
        self::assertSame('Winglet HTTP kernel fatal error', $fallbackLogger->lastRecord()['message']);
    }

    /** @param array<string,array<string,array{controller:class-string,action:string}>> $staticRoutes */
    private function application(array $staticRoutes, array $cors = ['enabled' => false]): Application
    {
        $root = sys_get_temp_dir() . '/winglet-kernel-' . bin2hex(random_bytes(4));
        mkdir($root, 0777, true);
        $config = $this->config($root, $cors);
        $logger = new ArrayLogger();
        $container = new ServiceContainer();
        $container->set(\Psr\Container\ContainerInterface::class, $container);
        $container->set(\Psr\Log\LoggerInterface::class, $logger);
        $container->set(Config::class, $config);

        $tenantResolver = new DefaultTenantResolver($config);
        $container->set(TenantResolverInterface::class, $tenantResolver);

        $routeFile = $root . '/routes.php';
        file_put_contents($routeFile, '<?php return ' . var_export([
            'static' => $staticRoutes,
            'dynamic' => [],
        ], true) . ';');
        $router = new Router($routeFile, false);
        $access = new AccessDecisionManager($container);
        $invoker = new ControllerInvoker($logger, $access, $container);
        $doctrine = new DoctrineManager($config, $logger, $root);
        $database = new DatabaseManager($config, $logger);
        $exceptionHandler = new ExceptionHandler($logger, $config);
        $tokens = new AnonymousTokenService();

        return new Application(
            basePath: $root,
            env: Env::fromDotenvIfPresent($root . '/.env'),
            config: $config,
            logger: $logger,
            accessLogger: $logger,
            securityLogger: $logger,
            container: $container,
            doctrine: $doctrine,
            db: $database,
            sessionManager: new SessionManager(),
            router: $router,
            controllerInvoker: $invoker,
            exceptionHandler: $exceptionHandler,
            tokenService: $tokens,
        );
    }

    private function config(string $root, array $cors = ['enabled' => false]): Config
    {
        $path = $root . '/config.php';
        file_put_contents($path, '<?php return ' . var_export([
            'app' => ['env' => 'prod'],
            'http' => [
                'request_context' => ['enabled' => true],
                'cors' => $cors,
                'access_log' => ['enabled' => false],
                'base_path' => '',
                'trusted_proxies' => ['trust_forwarded_prefix' => false],
            ],
            'tenancy' => ['mode' => 'disabled'],
            'database' => ['connection' => []],
            'doctrine' => ['enabled' => false],
        ], true) . ';');
        return Config::fromPhpFile($path);
    }

    private function backupServer(): void
    {
        foreach (['REQUEST_METHOD', 'REQUEST_URI', 'SCRIPT_NAME'] as $key) {
            if (array_key_exists($key, $_SERVER)) {
                $this->serverBackup[$key] = $_SERVER[$key];
            }
        }
    }
}

final class StubBootstrapper implements ApplicationBootstrapperInterface
{
    public int $calls = 0;

    public function __construct(
        private ?Application $application = null,
        private ?RuntimeException $exception = null,
    ) {}

    public function boot(): Application
    {
        $this->calls++;
        if ($this->exception !== null) {
            throw $this->exception;
        }
        return $this->application ?? throw new RuntimeException('Application missing');
    }
}

final class AnonymousTokenService implements JwtTokenServiceInterface
{
    public function parseAndValidate(string $token): AuthContext
    {
        return AuthContext::anonymous();
    }
}

final class KernelOkController
{
    /** @return array{ok:bool,anonymous:bool} */
    public function handleGet(Request $request): array
    {
        $auth = $request->getAttribute('auth');
        return ['ok' => true, 'anonymous' => $auth instanceof AuthContext && !$auth->isAuthenticated()];
    }
}

final class KernelFailureController
{
    public function handleGet(): Response
    {
        throw new RuntimeException('database password should not leak');
    }
}
