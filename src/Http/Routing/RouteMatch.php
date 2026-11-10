<?php
declare(strict_types=1);

namespace Winglet\Core\Http\Routing;

/**
 * Result of routing.
 *
 * - controller: fully qualified controller class name
 * - action: controller method name
 * - params: route params (named)
 * - access: optional access definition from route metadata
 */
final class RouteMatch
{
    /**
     * @param array<string,string> $params
     * @param array{min:int|null,max:int|null,groups:array<int,string>,checker:string|null}|null $access
     */
    public function __construct(
        public readonly string $controller,
        public readonly string $action,
        public readonly array $params = [],
        public readonly ?array $access = null,
    ) {}
}