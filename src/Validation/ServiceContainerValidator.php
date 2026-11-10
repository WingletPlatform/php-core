<?php
declare(strict_types=1);

namespace Winglet\Core\Validation;

use Psr\Container\ContainerInterface;
use Throwable;
use Winglet\Core\Container\ServiceContainer;

final class ServiceContainerValidator
{
    /**
     * @param list<string> $serviceIds
     * @return list<ServiceValidationError>
     */
    public function validate(ContainerInterface $container, array $serviceIds): array
    {
        $errors = [];

        foreach (array_values(array_unique($serviceIds)) as $serviceId) {
            if ($serviceId === '') {
                continue;
            }

            $metadata = $container instanceof ServiceContainer
                ? $container->getMetadata($serviceId)
                : [];

            try {
                $service = $container->get($serviceId);

                if (!is_object($service)) {
                    $errors[] = ServiceValidationError::nonObject($serviceId, get_debug_type($service), $metadata);
                }
            } catch (Throwable $e) {
                $errors[] = ServiceValidationError::fromThrowable($serviceId, $e, $metadata);
            }
        }

        return $errors;
    }
}
