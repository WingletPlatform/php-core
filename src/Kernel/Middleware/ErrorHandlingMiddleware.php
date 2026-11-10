<?php
declare(strict_types=1);

/**
 * Middleware that converts exceptions into JSON responses.
 *
 * Responsibilities:
 * - Wraps downstream middleware/controller execution in try/catch.
 * - Delegates exception mapping/formatting to ExceptionHandler.
 */

namespace Winglet\Core\Kernel\Middleware;

use Throwable;
use Winglet\Core\Http\Request;
use Winglet\Core\Http\Response;
use Winglet\Core\Http\Exception\ExceptionHandler;

final class ErrorHandlingMiddleware implements MiddlewareInterface
{
    public function __construct(private ExceptionHandler $handler) {}

    public function process(Request $request, callable $next): Response
    {
        try {
            return $next($request);
        } catch (Throwable $e) {
            return $this->handler->handle($e, $request);
        }
    }
}