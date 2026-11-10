<?php
declare(strict_types=1);

return [
    'app' => [
        'name' => 'Winglet App',
        'env' => $_ENV['APP_ENV'] ?? 'dev',
        'timezone' => $_ENV['APP_TIMEZONE'] ?? 'UTC',

        /**
         * Optional Winglet-specific extension packages.
         *
         * Each class must implement Winglet\Core\Extension\WingletExtensionInterface.
         * Example: \Winglet\Webhooks\Bridge\WebhookExtension::class
         */
        'extensions' => [],
    ],

    'dev' => [
        'secret' => 'CHANGE_ME_LONG_RANDOM',
    ],

    // Kept for compatibility with old token format.
    // Apps/instances should override environment.Secret via env or instance config.
    'environment' => [
        'Secret' => $_ENV['APP_SECRET'] ?? '',
    ],

    'controllers' => [
        'app' => [
            // Winglet appien oletusrakenne
            'namespace' => 'App\\Controller\\',
            'baseDir' => '/var/www/App/src',
            'subDir' => 'Controller',
        ],
        'framework' => [
            'list' => [
                \Winglet\Core\Http\Controller\HealthController::class,
                \Winglet\Core\Http\Controller\ReadinessController::class,
                \Winglet\Core\Http\Controller\EchoController::class,
                \Winglet\Core\Http\Controller\DevToolsController::class,
            ],
        ],
    ],
];
