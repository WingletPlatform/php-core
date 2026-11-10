<?php
declare(strict_types=1);

namespace Winglet\Core\Container;

use Psr\Container\ContainerExceptionInterface;
use RuntimeException;

/**
 * Generic container error (PSR-11).
 */
final class ContainerException extends RuntimeException implements ContainerExceptionInterface
{
    /**
     * Extra debug context for the exception.
     *
     * @var array<string, mixed>
     */
    private array $context = [];

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(string $message = '', array $context = [], int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }
}