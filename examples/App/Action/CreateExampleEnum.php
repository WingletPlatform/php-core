<?php
declare(strict_types=1);

namespace Winglet\Core\Application\Action;

use Winglet\Core\Kernel\Context\AppContext;
use App\Entities\ExampleEnumEntity; // consuming app's entity namespace

/**
 * Example action/command that uses AppContext.
 *
 * Called from controllers or other application-layer services.
 */
final class CreateExampleEnum
{
    public function __construct(private AppContext $ctx) {}

    public function run(string $name): ExampleEnumEntity
    {
        $entity = new ExampleEnumEntity();
        $entity->setName($name);

        $this->ctx->em->persist($entity);
        $this->ctx->em->flush();

        $this->ctx->log->info('Example enum created', [
            'id' => method_exists($entity, 'getId') ? $entity->getId() : null,
            'name' => $name,
        ]);

        return $entity;
    }
}