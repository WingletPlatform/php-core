<?php
declare(strict_types=1);

namespace Winglet\Core\Tests\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Winglet\Core\Doctrine\DoctrineManager;
use Winglet\Core\Support\Config;

final class DoctrineManagerTest extends TestCase
{
    public function testTransactionalUsesWrapInTransactionAndReturnsCallbackValue(): void
    {
        $config = Config::fromPhpFile(__DIR__ . '/fixtures/empty-config.php');
        $manager = new DoctrineManager($config, new NullLogger(), __DIR__);

        /** @var EntityManagerInterface&MockObject $entityManager */
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $entityManager
            ->expects($this->once())
            ->method('wrapInTransaction')
            ->willReturnCallback(function (callable $callback): string {
                return $callback();
            });

        $ref = new \ReflectionProperty($manager, 'em');
        $ref->setValue($manager, $entityManager);

        $result = $manager->transactional(function (EntityManagerInterface $em): string {
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            return 'ok';
        });

        self::assertSame('ok', $result);
    }
}
