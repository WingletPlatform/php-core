<?php
declare(strict_types=1);

namespace Winglet\Core\Integration\Events;

final class NullEventPublisher implements EventPublisherInterface
{
    public function publish(DomainEventInterface $event): void
    {
        // Optional event integrations are disabled; intentionally no-op.
    }
}
