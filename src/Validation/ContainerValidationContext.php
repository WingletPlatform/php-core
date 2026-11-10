<?php
declare(strict_types=1);

namespace Winglet\Core\Validation;

use Psr\Container\ContainerInterface;
use Throwable;
use Winglet\Core\Container\ServiceContainer;

final class ContainerValidationContext
{
    /** @var list<ServiceValidationError> */
    private array $errors = [];

    public function __construct(private readonly ContainerInterface $container) {}

    public function container(): ContainerInterface
    {
        return $this->container;
    }

    public function assertObject(mixed $value, string $label): void
    {
        if (!is_object($value)) {
            $this->errors[] = new ServiceValidationError(
                serviceId: $label,
                message: sprintf('Expected object, got %s.', get_debug_type($value)),
            );
        }
    }

    public function fail(string $label, Throwable $e): void
    {
        $metadata = $this->container instanceof ServiceContainer
            ? $this->container->getMetadata($label)
            : [];

        $this->errors[] = ServiceValidationError::fromThrowable($label, $e, $metadata);
    }

    /** @return list<ServiceValidationError> */
    public function errors(): array
    {
        return $this->errors;
    }
}
