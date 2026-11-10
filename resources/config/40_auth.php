<?php
declare(strict_types=1);

return [
    'authentication' => [
        'disable' => false,

        'jwt' => [
            'enabled' => true,
            'header' => 'Authorization',
            'prefix' => 'Bearer ',

            /**
             * Token mode:
             * - 'standard' => StandardJwtTokenService (RFC 7519)
             * - 'legacy'   => LegacyJwtTokenService (old JWT-like)
             *
             * Standard is the default for Winglet services. Legacy mode must
             * be enabled explicitly by old services that still validate the
             * historical WingNg token format.
             */
            'mode' => 'standard',

            /**
             * Standard JWT validation settings.
             * Supports key rotation via multiple keys and optional kid header,
             * and optionally JWKS (JWK Set) URL fetch + cache.
             */
            'standard' => [
                // Allowed algorithms (implemented: HS256, RS256)
                'algorithms' => ['HS256'],

                // Enforce issuer/audience when set (recommended in production)
                'issuer' => null,           // e.g. 'https://auth.example.com'
                'audience' => null,         // e.g. 'winglet-api'

                // Clock skew allowance
                'leeway_seconds' => 60,

                // If true, token MUST have a 'kid' header when using standard mode.
                'kid_required' => false,

                /**
                 * Key set for verification (rotation).
                 */
                'keys' => [
                    // 'default' => ['alg' => 'HS256', 'secret' => 'CHANGE_ME'],
                ],

                /**
                 * JWKS (JWK Set) support for RS256 rotation.
                 *
                 * Security hardening:
                 * - allowed_urls: allowlist of JWKS URLs that are permitted.
                 * - issuer_pins: map of issuer => jwks_url (pinning). If standard.issuer matches a pin,
                 *   jwks.url MUST equal that pinned URL.
                 */
                'jwks' => [
                    'enabled' => false,
                    'url' => null, // e.g. 'https://auth.example.com/.well-known/jwks.json'

                    'timeout_seconds' => 2,
                    'cache_ttl_seconds' => 300,

                    // Recommended runtime location: /var/www/var/cache (or a path under the runtime root).
                    'cache_dir' => null,

                    'use_stale_on_error' => true,
                    'max_stale_seconds' => 3600,

                    /**
                     * Allowlist of permitted JWKS URLs.
                     * If non-empty, jwks.url must be exactly one of these.
                     *
                     * Example:
                     *  ['https://auth.example.com/.well-known/jwks.json']
                     */
                    'allowed_urls' => [],

                    /**
                     * Issuer -> JWKS URL pins (optional).
                     * If standard.issuer is set and exists in this map,
                     * jwks.url must match the pinned URL exactly.
                     *
                     * Example:
                     *  [
                     *    'https://auth.example.com' => 'https://auth.example.com/.well-known/jwks.json',
                     *  ]
                     */
                    'issuer_pins' => [],
                ],

                'claims' => [
                    'client_id' => 'idi',
                    'session_id' => 'sid',
                    'session_level' => 'slv',
                    'identity_version' => 'jti',
                    'groups' => 'groups',
                    'privileges' => 'privileges',
                    'roles' => 'roles',
                    'groups_fallback' => 'grp',
                ],
            ],

            'legacy' => [
                'secret_config_key' => 'environment.Secret',
            ],
        ],

        'access' => [
            'deny_by_default' => false,
        ],
    ],
];
