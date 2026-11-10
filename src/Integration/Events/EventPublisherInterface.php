<?php
declare(strict_types=1);

namespace Winglet\Core\Integration\Events;

interface EventPublisherInterface
{
    public function publish(DomainEventInterface $event): void;
}
