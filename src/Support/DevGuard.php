<?php
declare(strict_types=1);

namespace Winglet\Core\Support;

use Winglet\Core\Http\Request;
use Winglet\Core\Http\Exception\HttpException;

final class DevGuard
{
    public static function assertDevAndSecret(Request $request, Config $config): void
    {
        $env = (string)$config->get('app.env', 'prod');
        if ($env !== 'dev') {
            throw new HttpException(404, 'Not found');
        }

        $expected = (string)$config->get('dev.secret', '');
        if ($expected === '') {
            throw new HttpException(500, 'dev.secret is not configured');
        }

        $got = (string)($request->headers['X-Dev-Secret'] ?? $request->headers['x-dev-secret'] ?? '');
        if ($got === '' || !hash_equals($expected, $got)) {
            throw new HttpException(401, 'Invalid dev secret');
        }
    }
}
