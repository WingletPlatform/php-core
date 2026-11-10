# HTTP Configuration

Winglet HTTP request-pipeline configuration lives under `http.*`.

CORS already uses this model with `http.cors.*`. The same namespace is now used for other HTTP-layer concerns so application developers can find request processing behavior in one place.

## Current structure

```php
return [
    'http' => [
        'base_path' => '',

        'trusted_proxies' => [
            'trust_forwarded_prefix' => true,
            'ips' => [],
            'forwarded_headers' => ['X-Forwarded-For', 'X-Forwarded-Proto', 'X-Forwarded-Host', 'X-Forwarded-Prefix'],
            'strategy' => 'default',
        ],

        'access_log' => [
            'enabled' => true,
            'level' => 'basic', // none|basic|extended|detail
            'include_headers' => false,
            'include_body' => false,
        ],

        'session' => [
            'enabled' => true,
            'header' => 'X-Session-Id',
            'cookie_name' => 'winglet_session',
            'same_site' => 'Lax',
            'secure' => false,
            'lifetime_seconds' => 3600,
            'storage' => 'none',
        ],

        'rate_limit' => [
            'enabled' => false,
            'limits' => [],
            'burst' => null,
            'storage' => null,
        ],

        'security_headers' => [
            'enabled' => false,
            'content_security_policy' => null,
            'strict_transport_security' => null,
            'x_frame_options' => null,
            'x_content_type_options' => null,
        ],

        'cors' => [
            'enabled' => false,
            'allow_origin' => [],
            'allow_headers' => 'Authorization, Content-Type, Accept, Origin, X-Requested-With, X-Tenant-Code, X-Request-Id, X-Correlation-Id',
            'allow_methods' => 'GET,POST,PUT,PATCH,DELETE,OPTIONS',
            'allow_credentials' => false,
            'expose_headers' => 'X-Request-Id, X-Correlation-Id',
            'max_age' => 0,
        ],
    ],
];
```

## Boundary between `http.*` and `logging.*`

`http.access_log.*` controls HTTP request logging behavior: whether access logging is enabled and how much request context is included.

The logger backend still belongs under `logging.access.*`:

```php
return [
    'logging' => [
        'access' => [
            'driver' => 'file',
            'severity' => 'info',
            'channel' => 'access',
            'path' => '/var/www/logs/access.log',
        ],
    ],
];
```

This keeps transport/backend logging concerns separate from HTTP request pipeline behavior.

## Reserved namespaces

`http.rate_limit.*` and `http.security_headers.*` are intentionally only configuration namespaces in this change. They do not enable a new rate limiter, CSP middleware, HSTS middleware, or any other new middleware by themselves.

They exist so future HTTP middleware can be configured consistently instead of adding new root-level config sections.

## Deprecated aliases

The following legacy keys are still supported when configuration is loaded through `Config::load()` or `Config::loadStack()`:

| Legacy key | New key |
|---|---|
| `logging.access.level` | `http.access_log.level` |
| `session.enabled` | `http.session.enabled` |
| `session.header` | `http.session.header` |
| `session.storage` | `http.session.storage` |
| `session.ttl_seconds` | `http.session.lifetime_seconds` |
| `security.session.header` | `http.session.header` |
| `http.trust_forwarded_prefix` | `http.trusted_proxies.trust_forwarded_prefix` |

Alias migration is applied per configuration layer before layers are merged. This preserves precedence correctly: an old application override still overrides the new framework default.

## Migration recommendation

Applications should move HTTP request-pipeline settings to the new keys during normal maintenance:

```php
return [
    'http' => [
        'access_log' => [
            'level' => 'extended',
        ],
        'session' => [
            'header' => 'X-Session-Id',
            'lifetime_seconds' => 3600,
        ],
        'trusted_proxies' => [
            'trust_forwarded_prefix' => true,
        ],
    ],
];
```

Leave `logging.access.*` in place for access log backend settings such as file path, channel, severity, and driver.

## CORS

CORS is disabled unless an application sets `http.cors.enabled` to `true`. When enabled, `allow_origin` must be an array of exact origins (scheme, host, and optional port), for example `['https://console.example.test']`. Wildcard origins and suffix patterns are not supported. Credentials are sent only for a matching explicitly listed origin. Keep `allow_credentials` false unless the application uses browser credentials.

Preflight `OPTIONS` requests are handled before authentication, tenant resolution, and route dispatch. The middleware checks the requested method and every requested header against `allow_methods` and `allow_headers`; rejected preflights return 403 without allow-origin/method/header response headers. Ordinary `OPTIONS` requests continue through routing. The middleware adds `Vary: Origin`, and preflights also vary on the requested method and headers.

Example for a specific development UI:

```php
'cors' => [
    'enabled' => true,
    'allow_origin' => ['http://localhost:5173'],
    'allow_methods' => ['GET', 'POST', 'OPTIONS'],
    'allow_headers' => ['Authorization', 'Content-Type', 'X-Request-Id'],
    'allow_credentials' => false,
    'max_age' => 300,
],
```

Legacy configurations that set `allow_origin` to `'*'`, `'request'`, or a single string no longer grant cross-origin access. Convert them to exact origin arrays. Base images no longer add CORS headers themselves, so API behavior is governed by Core and its application configuration.
