<?php
declare(strict_types=1);

namespace Winglet\Core\Validation;

interface ContainerValidationProbeInterface
{
    public function name(): string;

    public function validate(ContainerValidationContext $context): void;
}
