<?php
declare(strict_types=1);

namespace Winglet\Core\Integration\Events;

interface DomainEventInterface
{
    public function getEventId(): string;
    public function getEventType(): string;
    public function getEventVersion(): int;
    public function getOccurredAt(): \DateTimeImmutable;
    public function getSourceService(): string;
    public function getTenantId(): ?string;
    public function getEntityId(): string|int|null;
    /** @return array<string,mixed> */
    public function getData(): array;
    /** @return array<string,mixed> */
    public function getMeta(): array;
}
