<?php
declare(strict_types=1);

namespace Winglet\Core\Tests\Validation;

use stdClass;
use Winglet\Core\Container\ServiceContainer;
use Winglet\Core\Tests\TestCase;
use Winglet\Core\Validation\ServiceContainerValidator;

final class ServiceContainerValidatorTest extends TestCase
{
    public function testValidateReturnsNoErrorsForConstructableServices(): void
    {
        $container = new ServiceContainer();
        $container->set('object', new stdClass());

        $errors = (new ServiceContainerValidator())->validate($container, ['object']);

        self::assertSame([], $errors);
    }

    public function testValidateCollectsConstructionErrorsAndMetadata(): void
    {
        $container = new ServiceContainer();
        $container->setWithMetadata('broken.service', static function (): object {
            throw new \RuntimeException('Deliberately broken factory.');
        }, [
            'source' => 'tests/services.php',
        ]);

        $errors = (new ServiceContainerValidator())->validate($container, ['broken.service']);

        self::assertCount(1, $errors);
        self::assertSame('broken.service', $errors[0]->serviceId);
        self::assertSame('tests/services.php', $errors[0]->metadata['source']);
    }

    public function testValidateRejectsNonObjectService(): void
    {
        $container = new ServiceContainer();
        $container->set('scalar', 'value');

        $errors = (new ServiceContainerValidator())->validate($container, ['scalar']);

        self::assertCount(1, $errors);
        self::assertStringContainsString('instead of object', $errors[0]->message);
    }
}
