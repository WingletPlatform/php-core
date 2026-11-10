<?php

namespace Winglet\Core\Kernel\Middleware;

/**
 * Middleware contract for the framework pipeline.
 *
 * Responsibilities:
 * - Defines process(Request $request, callable $next): Response signature.
 *
 * Implemented by:
 * - ErrorHandlingMiddleware, SessionMiddleware, RoutingMiddleware, etc.
 * Called from:
 * - MiddlewarePipeline.
 */

use Winglet\Core\Http\Request;
use Winglet\Core\Http\Response;

interface MiddlewareInterface
{
    /** @param callable(Request):Response $next */
    public function process(Request $request, callable $next): Response;
}