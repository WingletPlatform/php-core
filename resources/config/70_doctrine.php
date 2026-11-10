<?php
declare(strict_types=1);

return [
    'doctrine' => [
        'enabled' => true,

        'dev_mode' => ($_ENV['APP_ENV'] ?? 'dev') !== 'prod',
        'auto_generate_proxies' => true,
        // Runtime-generated Doctrine proxies must live under /var/www/var, not /var/www/App.
        'proxy_dir' => 'var/doctrine/proxies',

        // Doctrine connection parameters are read from database.connection
        // so you only define DB host/user/password/dbname once.

        'mapping' => [
            'type' => 'attribute',
            'paths' => [
                'src/Entities',
            ],
            'namespaces' => [
                'App\\Entities',
            ],
        ],

        'cache' => [
            'metadata' => [
                'driver' => 'filesystem',
                'directory' => 'var/cache/doctrine',
                'default_ttl' => 0,
            ],
            'query' => [
                'driver' => 'filesystem',
                'directory' => 'var/cache/doctrine',
                'default_ttl' => 0,
            ],
            'result' => [
                'driver' => 'array',
                'default_ttl' => 0,
            ],
        ],
    ],
];
