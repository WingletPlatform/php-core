<?php
declare(strict_types=1);

namespace Winglet\Core\Kernel\Bootstrap;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Winglet\Core\Cli\Command\BuildRoutesCommand;
use Winglet\Core\Cli\Command\ValidateContainerCommand;
use Winglet\Core\Container\AppServiceLoader;
use Winglet\Core\Container\ControllerRegistrar;
use Winglet\Core\Container\ServiceContainer;
use Winglet\Core\Database\DatabaseManager;
use Winglet\Core\Doctrine\DoctrineManager;
use Winglet\Core\Extension\ExtensionLoader;
use Winglet\Core\Doctrine\Entity\AbstractEntity;
use Winglet\Core\Http\Controller\ControllerInvoker;
use Winglet\Core\Http\Exception\ExceptionHandler;
use Winglet\Core\Http\Exception\ApiExceptionMapperInterface;
use Winglet\Core\Http\Exception\NullApiExceptionMapper;
use Winglet\Core\Http\Exception\ApiErrorResponseFactoryInterface;
use Winglet\Core\Http\Exception\DefaultApiErrorResponseFactory;
use Winglet\Core\Kernel\Context\AppContextProviderInterface;
use Winglet\Core\Kernel\Context\DefaultAppContextProvider;
use Winglet\Core\Health\ReadinessCheckRunner;
use Winglet\Core\Http\Routing\Router;
use Winglet\Core\Logging\LoggerFactory;
use Winglet\Core\Integration\Events\EventPublisherInterface;
use Winglet\Core\Integration\Events\NullEventPublisher;
use Winglet\Core\Security\AccessControl\AccessDecisionManager;
use Winglet\Core\Security\Auth\Jwt\JwtTokenServiceInterface;
use Winglet\Core\Security\Auth\Jwt\DualJwtTokenService;
use Winglet\Core\Security\Auth\Jwt\LegacyJwtTokenService;
use Winglet\Core\Security\Auth\Jwt\StandardJwtTokenService;
use Winglet\Core\Security\Auth\Session\SessionManager;
use Winglet\Core\Support\Config;
use Winglet\Core\Support\Env;
use Winglet\Core\Tenancy\DefaultTenantResolver;
use Winglet\Core\Tenancy\TenantResolverInterface;

final class Bootstrapper implements ApplicationBootstrapperInterface
{
    public function __construct(
        private string $basePath
    ) {}

    public function boot(): Application
    {
        $basePath = rtrim($this->basePath, '/\\');
        $appRoot = $basePath . '/App';

        $env = Env::fromDotenvIfPresent($basePath . '/.env');

        $frameworkDefaults = dirname(__DIR__, 3) . '/resources/config';
        $appDefaults = $appRoot . '/resources/config';
        $instanceOverrides = $basePath . '/config';

        /*
         * Standard Winglet configuration stack.
         *
         * Precedence, lowest to highest:
         * 1. Framework defaults: vendor/winglet/core/resources/config
         * 2. Application defaults: /var/www/App/resources/config
         * 3. Instance overrides:  /var/www/config
         *
         * The runtime /config directory is intentionally only an override layer.
         * Application-specific defaults such as controller namespace, Doctrine
         * entity namespace and domain defaults must live in App/resources/config.
         */
        $config = Config::loadStack(
            frameworkDefaultsDir: $frameworkDefaults,
            appDefaultsDir: $appDefaults,
            instanceOverridesDir: $instanceOverrides
        );

        $jwtMode = strtolower((string)$config->get('authentication.jwt.mode', 'standard'));
        if (!in_array($jwtMode, ['standard', 'legacy', 'dual'], true)) {
            throw new \InvalidArgumentException('Unsupported authentication.jwt.mode: ' . $jwtMode);
        }

        $loggerFactory = new LoggerFactory();
        $logger = $loggerFactory->create($config);
        $accessLogger = $loggerFactory->create($config, 'logging.access');
        $securityLogger = $loggerFactory->create($config, 'logging.security');
        AbstractEntity::setExportLogger($logger);

        $container = new ServiceContainer();

        $container->set(ContainerInterface::class, $container);
        $container->set(LoggerInterface::class, $logger);
        $container->set('logger.access', $accessLogger);
        $container->set('logger.security', $securityLogger);

        $container->set('app.root', $appRoot);
        $container->set('runtime.root', $basePath);
        $container->set(Env::class, $env);
        $container->set(Config::class, $config);
        $container->singleton(NullEventPublisher::class, fn () => new NullEventPublisher());
        $container->alias(EventPublisherInterface::class, NullEventPublisher::class);
        $container->singleton(AppContextProviderInterface::class, fn (ContainerInterface $c) => new DefaultAppContextProvider(
            $c->get(EntityManagerInterface::class),
            $c->get(LoggerInterface::class),
            $c->get(Config::class),
        ));
        $container->singleton(ApiExceptionMapperInterface::class, fn () => new NullApiExceptionMapper());
        $container->singleton(ApiErrorResponseFactoryInterface::class, fn () => new DefaultApiErrorResponseFactory());
        $container->singleton(ExceptionHandler::class, fn (ContainerInterface $c) => new ExceptionHandler(
            $c->get(LoggerInterface::class),
            $c->get(Config::class),
            $c->get(ApiExceptionMapperInterface::class),
            $c->get(ApiErrorResponseFactoryInterface::class),
        ));
        $container->singleton(ReadinessCheckRunner::class, fn () => new ReadinessCheckRunner(
            $container->getTaggedAs('readiness.probe', \Winglet\Core\Health\ReadinessProbeInterface::class)
        ));

        $extensionMappingPaths = (new ExtensionLoader())->load($config, $container);

        $container->singleton(DefaultTenantResolver::class, fn (ContainerInterface $c) => new DefaultTenantResolver(
            $c->get(Config::class)
        ));
        $container->alias(TenantResolverInterface::class, DefaultTenantResolver::class);

        $container->singleton(DoctrineManager::class, fn (ContainerInterface $c) => new DoctrineManager(
            $c->get(Config::class),
            $c->get(LoggerInterface::class),
            $basePath,
            $extensionMappingPaths
        ));

        $container->singleton(EntityManagerInterface::class, fn (ContainerInterface $c) =>
            $c->get(DoctrineManager::class)->getEntityManager()
        );

        $reloadOnChange = (bool)$config->get('http.router.reload_on_change', true);
        $reloadCheckMs = (int)$config->get('http.router.reload_check_interval_ms', 0);

        $container->singleton(Router::class, fn () => new Router(
            $basePath . '/var/routes.php',
            $reloadOnChange,
            $reloadCheckMs
        ));

        if ($jwtMode === 'standard') {
            $container->alias(JwtTokenServiceInterface::class, StandardJwtTokenService::class);
        } elseif ($jwtMode === 'legacy') {
            $container->alias(JwtTokenServiceInterface::class, LegacyJwtTokenService::class);
        } else {
            $container->alias(JwtTokenServiceInterface::class, DualJwtTokenService::class);
        }

        (new AppServiceLoader())->loadIfPresent($basePath, $container);

        $appControllerDir = $appRoot . '/src/Controller';
        $frameworkControllerDir = dirname(__DIR__, 2) . '/Http/Controller';

        (new ControllerRegistrar($logger))->registerControllers($container, [
            $appControllerDir,
            $frameworkControllerDir,
        ]);

        $doctrine = $container->get(DoctrineManager::class);
        $db = $container->get(DatabaseManager::class);
        $sessionManager = $container->get(SessionManager::class);

        $router = $container->get(Router::class);
        $invoker = $container->get(ControllerInvoker::class);
        $container->get(AccessDecisionManager::class);

        $exceptionHandler = $container->get(ExceptionHandler::class);
        $tokenService = $container->get(JwtTokenServiceInterface::class);

        $container->tag(
            BuildRoutesCommand::class,
            'cli.command'
        );
        $container->tag(
            ValidateContainerCommand::class,
            'cli.command'
        );

        return new Application(
            basePath: $basePath,
            env: $env,
            config: $config,
            logger: $logger,
            accessLogger: $accessLogger,
            securityLogger: $securityLogger,
            container: $container,
            doctrine: $doctrine,
            db: $db,
            sessionManager: $sessionManager,
            router: $router,
            controllerInvoker: $invoker,
            exceptionHandler: $exceptionHandler,
            tokenService: $tokenService,
        );
    }

}
