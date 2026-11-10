<?php
declare(strict_types=1);

/**
 * Base HTTP exception with an associated HTTP status code.
 *
 * Notes:
 * - Supports *internal* context for logging.
 * - Public API responses must NOT expose internal messages or context.
 *   ExceptionHandler is responsible for returning safe, generic messages.
 */

namespace Winglet\Core\Http\Exception;

use RuntimeException;
use Throwable;

class HttpException extends RuntimeException
{
    /**
     * @param int $statusCode HTTP status code for the response mapper.
     * @param string $publicMessage Short message (can be used by server-side code), but it is not returned directly.
     * @param array<string,mixed> $context Extra internal context for logs (never return to client).
     * @param Throwable|null $previous Previous exception
     * @param string|null $internalMessage Optional internal message (if you want a log message different from publicMessage)
     */
    public function __construct(
        public readonly int $statusCode,
        string $publicMessage = '',
        public readonly array $context = [],
        ?Throwable $previous = null,
        ?string $internalMessage = null,
    ) {
        parent::__construct($internalMessage ?? $publicMessage, 0, $previous);
    }
}
