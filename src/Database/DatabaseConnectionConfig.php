<?php
declare(strict_types=1);

namespace Winglet\Core\Database;

use Winglet\Core\Exceptions\ConfigurationException;

/**
 * Database connection configuration helper.
 *
 * Winglet supports a single canonical configuration format for database
 * connection parameters. Both the direct PDO layer and Doctrine must read the
 * same keys from `database.connection`.
 *
 * Canonical keys:
 * - driver   (e.g. pdo_mysql)
 * - host
 * - port
 * - dbname
 * - user
 * - password
 * - charset
 * - timezone (optional)
 *
 * No legacy aliases are supported.
 */
final class DatabaseConnectionConfig
{
    /**
     * @param array<string,mixed> $conn
     * @return array<string,mixed>
     */
    public static function normalize(array $conn): array
    {
        // Defaults
        $conn['driver']  = $conn['driver']  ?? 'pdo_mysql';
        $conn['host']    = $conn['host']    ?? 'localhost';
        $conn['port']    = isset($conn['port']) ? (int)$conn['port'] : 3306;
        $conn['charset'] = $conn['charset'] ?? 'utf8mb4';

        // Validation (single canonical format)
        if (!isset($conn['dbname']) || !is_string($conn['dbname']) || $conn['dbname'] === '') {
            throw new ConfigurationException('database.connection.dbname is required');
        }
        if (!isset($conn['user']) || !is_string($conn['user']) || $conn['user'] === '') {
            throw new ConfigurationException('database.connection.user is required');
        }

        // Password can be empty, but the key must exist to avoid ambiguity.
        if (!array_key_exists('password', $conn)) {
            throw new ConfigurationException('database.connection.password is required (can be empty string)');
        }

        return $conn;
    }
}