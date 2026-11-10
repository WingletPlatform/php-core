<?php
declare(strict_types=1);

namespace Winglet\Core\Validation;

use Throwable;

final class ServiceValidationError
{
    /**
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public readonly string $serviceId,
        public readonly string $message,
        public readonly ?string $exceptionClass = null,
        public readonly ?string $file = null,
        public readonly ?int $line = null,
        public readonly array $metadata = [],
    ) {}

    /** @param array<string,mixed> $metadata */
    public static function fromThrowable(string $serviceId, Throwable $e, array $metadata = []): self
    {
        return new self(
            serviceId: $serviceId,
            message: $e->getMessage(),
            exceptionClass: $e::class,
            file: $e->getFile(),
            line: $e->getLine(),
            metadata: $metadata,
        );
    }

    /** @param array<string,mixed> $metadata */
    public static function nonObject(string $serviceId, string $type, array $metadata = []): self
    {
        return new self(
            serviceId: $serviceId,
            message: sprintf('Container returned %s instead of object.', $type),
            metadata: $metadata,
        );
    }
}
