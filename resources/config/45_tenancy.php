<?php
declare(strict_types=1);

return [
    'tenancy' => [
        'mode' => $_ENV['TENANCY_MODE'] ?? 'disabled',
        'default_tenant_code' => $_ENV['TENANCY_DEFAULT_TENANT_CODE'] ?? 'default',
        'resolve_from' => $_ENV['TENANCY_RESOLVE_FROM'] ?? 'auth',
        'header_name' => $_ENV['TENANCY_HEADER_NAME'] ?? 'X-Tenant-Code',
        'claim_name' => $_ENV['TENANCY_CLAIM_NAME'] ?? 'tenant_code',
        'route_param_name' => $_ENV['TENANCY_ROUTE_PARAM_NAME'] ?? 'tenant',
    ],
];
