<?php
declare(strict_types=1);

namespace Winglet\Core\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Winglet\Core\Support\Config;

final class DoctrineManager
{
    private ?EntityManagerInterface $em = null;

    /** @param list<string> $extraMappingPaths */
    public function __construct(
        private Config $config,
        private LoggerInterface $logger,
        private string $basePath,
        private array $extraMappingPaths = [],
    ) {}

    public function getEntityManager(): EntityManagerInterface
    {
        if ($this->em !== null) {
            return $this->em;
        }

        $this->em = EntityManagerFactory::create(
            config: $this->config,
            logger: $this->logger,
            basePath: $this->basePath,
            extraMappingPaths: $this->extraMappingPaths
        );

        return $this->em;
    }

    /**
     * Run a callback inside a DB transaction.
     *
     * Usage:
     *   $result = $doctrine->transactional(fn(EntityManagerInterface $em) => ...);
     *
     * Doctrine is lazily initialized the first time this is called.
     */
    public function transactional(callable $fn): mixed
    {
        $em = $this->getEntityManager();

        return $em->wrapInTransaction(fn() => $fn($em));
    }
}