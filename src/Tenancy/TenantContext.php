<?php
declare(strict_types=1);

namespace Winglet\Core\Tenancy;

final class TenantContext
{
    /** @param array<string,mixed> $metadata */
    public function __construct(
        public readonly bool $enabled,
        public readonly string $mode,
        public readonly ?string $tenantCode,
        public readonly string $source,
        public readonly bool $resolved,
        public readonly array $metadata = [],
    ) {}

    public static function disabled(): self
    {
        return new self(false, 'disabled', null, 'disabled', true, []);
    }

    public static function single(string $tenantCode, string $source = 'config'): self
    {
        return new self(true, 'single', $tenantCode, $source, true, []);
    }

    /** @param array<string,mixed> $metadata */
    public static function multi(string $tenantCode, string $source, array $metadata = []): self
    {
        return new self(true, 'multi', $tenantCode, $source, true, $metadata);
    }

    /** @param array<string,mixed> $metadata */
    public static function unresolved(string $mode, string $source, array $metadata = []): self
    {
        return new self($mode !== 'disabled', $mode, null, $source, false, $metadata);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function isResolved(): bool
    {
        return $this->resolved;
    }

    public function hasTenantCode(): bool
    {
        return $this->tenantCode !== null && $this->tenantCode !== '';
    }

    public function getTenantCode(): ?string
    {
        return $this->tenantCode;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'mode' => $this->mode,
            'tenantCode' => $this->tenantCode,
            'source' => $this->source,
            'resolved' => $this->resolved,
            'metadata' => $this->metadata,
        ];
    }
}
