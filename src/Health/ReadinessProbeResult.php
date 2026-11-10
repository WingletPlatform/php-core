<?php
declare(strict_types=1);

namespace Winglet\Core\Health;

final readonly class ReadinessProbeResult
{
    public function __construct(
        public bool $ready,
        public ?string $code = null,
        public ?string $message = null,
    ) {}

    public static function ready(): self
    {
        return new self(true);
    }

    public static function notReady(string $code, string $message = 'Not ready'): self
    {
        return new self(false, $code, $message);
    }
}
