<?php
declare(strict_types=1);

namespace Winglet\Core\Logging;

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Winglet\Core\Support\Config;

final class LoggerFactory
{
    public function create(Config $config, string $rootKey = 'logging'): LoggerInterface
    {
        $driver  = strtolower((string)$config->get($rootKey . '.driver', 'null'));
        $level   = strtolower((string)$config->get($rootKey . '.severity', $config->get($rootKey . '.level', 'info')));
        $channel = (string)$config->get($rootKey . '.channel', 'app');
        $path    = (string)$config->get($rootKey . '.path', '/var/www/logs/app.log');

        $monologLevel = $this->toMonologLevel($level);

        return match ($driver) {
            'file' => $this->createStreamLogger($channel, $path, $monologLevel),
            'stdout' => $this->createStreamLogger($channel, 'php://stdout', $monologLevel),
            'stderr' => $this->createStreamLogger($channel, 'php://stderr', $monologLevel),
            'null' => new NullLogger(),
            default => new NullLogger(),
        };
    }

    private function createStreamLogger(string $channel, string $stream, Level $level): LoggerInterface
    {
        if ($stream !== 'php://stdout' && $stream !== 'php://stderr') {
            @mkdir(dirname($stream), 0775, true);
        }

        $logger = new Logger($channel);
        $logger->pushHandler(new StreamHandler($stream, $level, true, 0664, true));
        return $logger;
    }

    private function toMonologLevel(string $level): Level
    {
        return match ($level) {
            'debug' => Level::Debug,
            'info' => Level::Info,
            'notice' => Level::Notice,
            'warning' => Level::Warning,
            'error' => Level::Error,
            'critical' => Level::Critical,
            'alert' => Level::Alert,
            'emergency' => Level::Emergency,
            default => Level::Info,
        };
    }
}
