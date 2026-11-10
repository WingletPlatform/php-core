<?php
declare(strict_types=1);

/**
 * 404 Not Found HTTP exception.
 *
 * Responsibilities:
 * - Represents missing route/resource cases.
 *
 * Thrown from:
 * - RoutingMiddleware when Router cannot match a request.
 */


namespace Winglet\Core\Http\Exception;

final class NotFoundException extends HttpException
{
    public function __construct(string $message = 'Not found')
    {
        parent::__construct(404, $message);
    }
}
