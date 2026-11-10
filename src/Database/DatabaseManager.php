<?php
declare(strict_types=1);

namespace Winglet\Core\Database;

use PDO;
use PDOException;
use PDOStatement;
use Psr\Log\LoggerInterface;
use Winglet\Core\Support\Config;
use Winglet\Core\Exceptions\ConfigurationException;
use Winglet\Core\Exceptions\RuntimeException;

/**
 * Lazy direct database access (PDO).
 *
 * Responsibilities:
 * - Creates PDO connection on first use only (lazy).
 * - Provides convenience methods for common query patterns:
 *   fetchOne, fetchAll, fetchAssoc, fetchColumn, execute, insert, transactions.
 * - Uses prepared statements to avoid manual escaping.
 *
 * Called from:
 * - Controllers / services that need direct SQL (when Doctrine is not ideal).
 *
 * Config source:
 * - database.connection.* (shared with Doctrine)
 */
final class DatabaseManager
{
    private ?PDO $pdo = null;

    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $conn = $this->config->get('database.connection');
        if (!is_array($conn)) {
            throw new ConfigurationException('Missing database.connection configuration');
        }

        // Enforce canonical DB config shared with Doctrine.
        $conn = DatabaseConnectionConfig::normalize($conn);

        $host = (string)($conn['host'] ?? '');
        $db   = (string)($conn['dbname'] ?? '');
        $user = (string)($conn['user'] ?? '');
        $pass = (string)($conn['password'] ?? '');
        $port = (int)($conn['port'] ?? 3306);
        $charset = (string)($conn['charset'] ?? 'utf8mb4');

        if ($host === '' || $db === '' || $user === '') {
            throw new ConfigurationException('Invalid database.connection (host/dbname/user required)');
        }

        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        $extra = $this->config->get('database.options', []);
        if (is_array($extra)) {
            $options = $options + $extra;
        }

        try {
            $this->logger->debug('Initializing PDO connection', ['dsn' => $dsn]);
            $this->pdo = new PDO($dsn, $user, $pass, $options);

            // Optional session settings
            $tz = $conn['timezone'] ?? null;
            if (is_string($tz) && $tz !== '') {
                $this->pdo->exec("SET time_zone = " . $this->pdo->quote($tz));
            }

            return $this->pdo;
        } catch (PDOException $e) {
            $this->logger->error('PDO connection failed', ['message' => $e->getMessage()]);
            throw new RuntimeException('Database connection failed', previous: $e);
        }
    }

    /** @return array<string,mixed>|null */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->prepareExecute($sql, $params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<int,array<string,mixed>> */
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->prepareExecute($sql, $params);
        return $stmt->fetchAll();
    }

    /**
     * Fetch keyed array like [id => row] or [key => value] when $valueField provided.
     *
     * @return array<mixed,mixed>
     */
    public function fetchAssoc(string $sql, string $keyField, ?string $valueField = null, array $params = []): array
    {
        $stmt = $this->prepareExecute($sql, $params);
        $out = [];

        while ($row = $stmt->fetch()) {
            if (!array_key_exists($keyField, $row)) {
                continue;
            }
            $k = $row[$keyField];
            $out[$k] = $valueField === null ? $row : ($row[$valueField] ?? null);
        }

        return $out;
    }

    /** @return array<int,mixed> */
    public function fetchColumn(string $sql, array $params = [], int $columnIndex = 0): array
    {
        $stmt = $this->prepareExecute($sql, $params);
        $out = [];
        while (($v = $stmt->fetchColumn($columnIndex)) !== false) {
            $out[] = $v;
        }
        return $out;
    }

    /**
     * Execute INSERT/UPDATE/DELETE; returns affected rows.
     */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->prepareExecute($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * Execute INSERT and return lastInsertId (string per PDO).
     */
    public function insert(string $sql, array $params = []): string
    {
        $this->execute($sql, $params);
        $id = $this->pdo()->lastInsertId();
        if ($id === false) {
            throw new \RuntimeException('Failed to read lastInsertId from PDO');
        }
        return $id;
    }

    public function begin(): void { $this->pdo()->beginTransaction(); }
    public function commit(): void { $this->pdo()->commit(); }
    public function rollBack(): void { $this->pdo()->rollBack(); }

    /**
     * @template T
     * @param callable(self):T $fn
     * @return T
     */
    public function transactional(callable $fn): mixed
    {
        $this->begin();
        try {
            $result = $fn($this);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo()->inTransaction()) {
                $this->rollBack();
            }
            throw $e;
        }
    }

    private function prepareExecute(string $sql, array $params): PDOStatement
    {
        try {
            $stmt = $this->pdo()->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            $this->logger->error('SQL error', [
                'sql' => $sql,
                'params' => $this->safeParams($params),
                'message' => $e->getMessage(),
            ]);
            throw new RuntimeException('Database query failed', previous: $e);
        }
    }

    private function safeParams(array $params): array
    {
        // Avoid logging huge blobs
        $out = [];
        foreach ($params as $k => $v) {
            if (is_string($v) && strlen($v) > 200) {
                $out[$k] = substr($v, 0, 200) . '…';
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    public function query(): QueryBuilder
    {
        return new QueryBuilder($this);
    }
}