<?php
declare(strict_types=1);

namespace Winglet\Core\Tenancy;

use Winglet\Core\Http\Exception\HttpException;

final class TenantResolutionException extends HttpException
{
    /** @param array<string,mixed> $context */
    public function __construct(string $message, int $statusCode = 400, array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct($statusCode, $message, $context, $previous);
    }
}
