<?php
declare(strict_types=1);

namespace Winglet\Core\Http\Exception;

final readonly class ApiError
{
    /**
     * @param array<string,mixed> $details
     * @param array<string,string> $headers
     */
    public function __construct(
        public int $status,
        public string $code,
        public string $message,
        public array $details = [],
        public array $headers = [],
    ) {}
}
