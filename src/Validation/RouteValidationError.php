<?php
declare(strict_types=1);

namespace Winglet\Core\Validation;

final class RouteValidationError
{
    public function __construct(
        public readonly string $route,
        public readonly string $message,
        public readonly ?string $controller = null,
        public readonly ?string $action = null,
    ) {}
}
