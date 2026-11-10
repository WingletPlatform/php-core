<?php
declare(strict_types=1);

namespace Winglet\Core\Http\Context;

final readonly class RequestContext
{
    public function __construct(
        public string $requestId,
        public string $correlationId,
    ) {}

    public static function unavailable(): self
    {
        return new self('', '');
    }
}
