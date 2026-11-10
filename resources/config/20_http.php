<?php
declare(strict_types=1);

return [
    'http' => [
        'request_context' => [
            'enabled' => true,
            'request_id_header' => 'X-Request-Id',
            'correlation_id_header' => 'X-Correlation-Id',
            'accept_inbound_correlation_id' => true,
            'max_length' => 128,
        ],
        // Mount prefix, example "/api/customers", "" or "/" if empty trying to autodetect
        'base_path' => '',

        'trusted_proxies' => [
            // Traefik: X-Forwarded-Prefix in use (might be disabled in some cases).
            // Former key: http.trust_forwarded_prefix.
            'trust_forwarded_prefix' => true,

            // Reserved for future proxy trust policy support. No middleware behavior is added here yet.
            'ips' => [],
            'forwarded_headers' => ['X-Forwarded-For', 'X-Forwarded-Proto', 'X-Forwarded-Host', 'X-Forwarded-Prefix'],
            'strategy' => 'default',
        ],

        'json' => [
            'pretty' => false,          // dev apps can override to true
            'unescaped_slashes' => true,
            'unescaped_unicode' => true,
        ],

        'access_log' => [
            // HTTP request/access log behavior. Logger backend settings remain under logging.access.*.
            // Former behavior key: logging.access.level.
            'enabled' => true,
            'level' => strtolower((string)($_ENV['ACCESS_LOG_LEVEL'] ?? 'basic')), // none|basic|extended|detail
            'include_headers' => false, // reserved; current detail level already includes sanitized headers
            'include_body' => false,    // reserved; raw/file response bodies are never logged by default
        ],

        'session' => [
            // Compatibility view only. Winglet Identity owns session creation, expiry,
            // elevation and revocation. Core derives the request Session object solely
            // from the validated JWT sid claim in AuthContext.
            'source' => 'validated_auth_context',
        ],

        'rate_limit' => [
            // Reserved configuration namespace. No rate limiter middleware is enabled by this change.
            'enabled' => false,
            'limits' => [],
            'burst' => null,
            'storage' => null,
        ],

        'security_headers' => [
            // Reserved configuration namespace. No security header middleware is enabled by this change.
            'enabled' => false,
            'content_security_policy' => null,
            'strict_transport_security' => null,
            'x_frame_options' => null,
            'x_content_type_options' => null,
        ],

        'cors' => [
            // Disabled by default. Applications must opt in and list exact origins.
            'enabled' => false,
            'allow_origin' => [],
            'allow_headers' => 'Authorization, Content-Type, Accept, Origin, X-Requested-With, X-Tenant-Code, X-Request-Id, X-Correlation-Id',
            'allow_methods' => 'GET,POST,PUT,PATCH,DELETE,OPTIONS',
            'allow_credentials' => false,
            'expose_headers' => 'X-Request-Id, X-Correlation-Id',
            'max_age' => 0,
        ],

        /**
         * Router hot-reload / caching behavior.
         *
         * - In dev: allow reload on route map changes.
         * - In prod: disable reload checks entirely (fastest).
         */
        'router' => [
            // If true, Router checks filemtime and reloads map when routes.php changes
            'reload_on_change' => ($_ENV['APP_ENV'] ?? 'dev') !== 'prod',

            // Throttle how often filemtime is checked (milliseconds).
            // 0 = check every request (dev). In prod keep reload_on_change=false.
            'reload_check_interval_ms' => (int)($_ENV['ROUTER_RELOAD_CHECK_MS'] ?? 0),
        ],
    ],
];
