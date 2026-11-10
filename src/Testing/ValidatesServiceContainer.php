<?php
declare(strict_types=1);

namespace Winglet\Core\Testing;

use Winglet\Core\Validation\ServiceContainerValidator;

// @phpstan-ignore-next-line trait.unused
trait ValidatesServiceContainer
{
    /**
     * @param list<string> $serviceIds
     */
    protected function assertContainerServicesAreValid(array $serviceIds): void
    {
        $validator = new ServiceContainerValidator();
        $errors = $validator->validate($this->container(), $serviceIds);

        if ($errors !== []) {
            self::fail($this->formatContainerValidationErrors($errors));
        }

        self::assertTrue(true);
    }

    /**
     * Host tests using this trait must expose their application/container instance.
     */
    abstract protected function container(): \Psr\Container\ContainerInterface;

    /**
     * @param list<\Winglet\Core\Validation\ServiceValidationError> $errors
     */
    private function formatContainerValidationErrors(array $errors): string
    {
        $lines = ['Container validation failed:'];

        foreach ($errors as $error) {
            $line = sprintf('- %s: %s', $error->serviceId, $error->message);
            $source = $error->metadata['source'] ?? null;
            if (is_string($source) && $source !== '') {
                $line .= sprintf(' (registered in %s)', $source);
            }
            $lines[] = $line;
        }

        return implode(PHP_EOL, $lines);
    }
}
