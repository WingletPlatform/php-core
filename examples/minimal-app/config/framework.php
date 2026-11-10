<?php

/**
 * Example application configuration for the framework.
 *
 * Responsibilities:
 * - Provides basic settings for app/logging/doctrine/session.
 *
 * Loaded by:
 * - Bootstrapper via Config::fromPhpFile().
 */


return [
    'app' => [
        'name' => 'Minimal App',
        'env' => 'dev',
    ],
    'logging' => [
        'driver' => 'null',
    ],
    'doctrine' => [
        'dev' => true,
        'connection' => [
            'driver'   => 'pdo_mysql',
            'host'     => 'localhost',
            'port'     => 3306,
            'dbname'   => 'app',
            'user'     => 'user',
            'password' => 'pass',
            'charset'  => 'utf8mb4',
        ],

        'mapping' => [
            'type' => 'attribute',               // attribute | annotation (future)
            'paths' => ['src/Entity'],            // relative to app base path
        ],

        'proxy_dir' => 'var/doctrine/proxies',

        'cache' => [
            'metadata' => [
                'driver' => 'filesystem',
                'directory' => 'var/cache/doctrine',
                'default_ttl' => 0
            ],
            'query' => [
                'driver' => 'filesystem',
                'directory' => 'var/cache/doctrine',
                'default_ttl' => 0
            ],
            'result' => [
                'driver' => 'array'
            ],
        ],

        'sql_logger' => [
            'enabled' => true
        ],
    ],
    'session' => [
        // later: storage, ttl, cookie settings
    ],
];

/*
 * return [
];
 */