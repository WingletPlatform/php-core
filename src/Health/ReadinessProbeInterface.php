<?php
declare(strict_types=1);

namespace Winglet\Core\Health;

interface ReadinessProbeInterface
{
    public function name(): string;

    public function check(): ReadinessProbeResult;
}
