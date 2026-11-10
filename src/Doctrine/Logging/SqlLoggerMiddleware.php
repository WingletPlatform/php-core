<?php
declare(strict_types=1);

namespace Winglet\Core\Doctrine\Logging;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result as DriverResult;
use Doctrine\DBAL\Driver\Statement as DriverStatement;
use Psr\Log\LoggerInterface;

/**
 * Logs SQL statements executed through Doctrine DBAL driver middleware.
 *
 * Compatible with Doctrine DBAL 4 middleware interfaces.
 */
final class SqlLoggerMiddleware implements Middleware
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        return new class($driver, $this->logger) extends AbstractDriverMiddleware {
            public function __construct(
                Driver $wrappedDriver,
                private LoggerInterface $logger,
            ) {
                parent::__construct($wrappedDriver);
            }

            public function connect(#[\SensitiveParameter] array $params): DriverConnection
            {
                return new class(parent::connect($params), $this->logger) extends AbstractConnectionMiddleware {
                    public function __construct(
                        DriverConnection $wrappedConnection,
                        private LoggerInterface $logger,
                    ) {
                        parent::__construct($wrappedConnection);
                    }

                    public function prepare(string $sql): DriverStatement
                    {
                        return new class(parent::prepare($sql), $this->logger, $sql) extends AbstractStatementMiddleware {
                            public function __construct(
                                DriverStatement $wrappedStatement,
                                private LoggerInterface $logger,
                                private string $sql,
                            ) {
                                parent::__construct($wrappedStatement);
                            }

                            public function execute(): DriverResult
                            {
                                $this->logger->debug('SQL execute', [
                                    'sql' => $this->sql,
                                ]);

                                return parent::execute();
                            }
                        };
                    }

                    public function query(string $sql): DriverResult
                    {
                        $this->logger->debug('SQL query', [
                            'sql' => $sql,
                        ]);

                        return parent::query($sql);
                    }

                    public function exec(string $sql): int|string
                    {
                        $this->logger->debug('SQL exec', [
                            'sql' => $sql,
                        ]);

                        return parent::exec($sql);
                    }
                };
            }
        };
    }
}
