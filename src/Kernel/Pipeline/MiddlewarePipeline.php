<?php
declare(strict_types=1);

/**
 * Simple middleware pipeline executor.
 *
 * Responsibilities:
 * - Stores ordered middleware list.
 * - Builds a callable chain and executes middleware sequentially.
 * - Provides a minimal "end handler" fallback if nothing returns a response.
 *
 * Called from:
 * - HttpKernel to run the request lifecycle.
 */

namespace Winglet\Core\Kernel\Pipeline;

use Winglet\Core\Http\Request;
use Winglet\Core\Http\Response;
use Winglet\Core\Kernel\Middleware\MiddlewareInterface;

final class MiddlewarePipeline
{
    /** @param MiddlewareInterface[] $middleware */
    public function __construct(private array $middleware) {}

    public function handle(Request $request): Response
    {
        $handler = array_reduce(
            array_reverse($this->middleware),
            fn ($next, MiddlewareInterface $mw) => fn (Request $r) => $mw->process($r, $next),
            fn (Request $r) => Response::json(['error' => 'No handler'], 500)
        );

        return $handler($request);
    }
}