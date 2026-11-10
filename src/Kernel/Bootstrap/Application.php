<?php
declare(strict_types=1);

namespace Winglet\Core\Kernel\Bootstrap;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Winglet\Core\Database\DatabaseManager;
use Winglet\Core\Doctrine\DoctrineManager;
use Winglet\Core\Http\Controller\ControllerInvoker;
use Winglet\Core\Http\Exception\ExceptionHandler;
use Winglet\Core\Http\Routing\Router;
use Winglet\Core\Security\Auth\Jwt\JwtTokenServiceInterface;
use Winglet\Core\Security\Auth\Session\SessionManager;
use Winglet\Core\Support\Config;
use Winglet\Core\Support\Env;

final class Application
{
    public function __construct(
        public readonly string $basePath,
        public readonly Env $env,
        public readonly Config $config,
        public readonly LoggerInterface $logger,
        public readonly LoggerInterface $accessLogger,
        public readonly LoggerInterface $securityLogger,

        public readonly ContainerInterface $container,

        public readonly DoctrineManager $doctrine,
        public readonly DatabaseManager $db,

        public readonly SessionManager $sessionManager,
        public readonly Router $router,
        public readonly ControllerInvoker $controllerInvoker,
        public readonly ExceptionHandler $exceptionHandler,

        public readonly JwtTokenServiceInterface $tokenService,
    ) {}
}
