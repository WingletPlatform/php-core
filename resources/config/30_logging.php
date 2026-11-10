<?php

declare(strict_types=1);

return [
    'logging' => [
        'driver' => $_ENV['LOG_DRIVER'] ?? 'file',
        'level' => $_ENV['LOG_LEVEL'] ?? 'info',
        'channel' => $_ENV['LOG_CHANNEL'] ?? 'app',
        'path' => $_ENV['LOG_PATH'] ?? '/var/www/logs/app.log',
        'access' => [
            // Logger backend for HTTP access logs. Request logging behavior lives under http.access_log.*.
            // Deprecated behavior alias still supported: logging.access.level.
            'driver' => $_ENV['ACCESS_LOG_DRIVER'] ?? ($_ENV['LOG_DRIVER'] ?? 'file'),
            'severity' => $_ENV['ACCESS_LOG_SEVERITY'] ?? 'info',
            'channel' => $_ENV['ACCESS_LOG_CHANNEL'] ?? 'access',
            'path' => $_ENV['ACCESS_LOG_PATH'] ?? '/var/www/logs/access.log',
        ],
        'security' => [
            'driver' => $_ENV['SECURITY_LOG_DRIVER'] ?? ($_ENV['LOG_DRIVER'] ?? 'file'),
            'level' => $_ENV['SECURITY_LOG_LEVEL'] ?? 'warning',
            'channel' => $_ENV['SECURITY_LOG_CHANNEL'] ?? 'security',
            'path' => $_ENV['SECURITY_LOG_PATH'] ?? '/var/www/logs/security.log',
        ],
    ],
];
