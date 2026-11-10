<?php
declare(strict_types=1);

namespace Winglet\Core\Tests\Container;

use Winglet\Core\Container\ContainerException;
use Winglet\Core\Container\ServiceContainer;
use Winglet\Core\Tests\TestCase;

interface FooInterface { public function id(): string; }

final class Foo implements FooInterface
{
    public function __construct(public readonly Bar $bar) {}
    public function id(): string { return 'foo:' . $this->bar->id(); }
}

final class Bar
{
    public function id(): string { return 'bar'; }
}

final class NeedsScalar
{
    public function __construct(public readonly string $name) {}
}

final class NeedsDefaultScalar
{
    public function __construct(public readonly string $name = 'default') {}
}

final class NeedsOptionalObject
{
    public function __construct(public readonly ?Bar $bar = null) {}
}

final class NeedsNullableObjectWithoutDefault
{
    public function __construct(public readonly ?Bar $bar) {}
}

final class NeedsOptionalScalar
{
    public function __construct(public readonly ?string $name = null) {}
}

final class ServiceContainerTest extends TestCase
{
    public function testSingletonFactoryIsExecutedOnce(): void
    {
        $c = new ServiceContainer();
        $calls = 0;

        $c->singleton('thing', function () use (&$calls) {
            $calls++;
            return (object)['n' => $calls];
        });

        $a = $c->get('thing');
        $b = $c->get('thing');

        $this->assertSame(1, $calls);
        $this->assertSame($a, $b);
    }

    public function testAliasResolvesToConcrete(): void
    {
        $c = new ServiceContainer();
        $c->alias(FooInterface::class, Foo::class);

        $foo = $c->get(FooInterface::class);

        $this->assertInstanceOf(Foo::class, $foo);
        $this->assertSame('foo:bar', $foo->id());
    }

    public function testAutowiringResolvesConcreteDependencies(): void
    {
        $c = new ServiceContainer();

        $foo = $c->get(Foo::class);

        $this->assertInstanceOf(Foo::class, $foo);
        $this->assertInstanceOf(Bar::class, $foo->bar);
    }

    public function testAutowiringFailsForScalarWithoutDefinition(): void
    {
        $c = new ServiceContainer();

        $this->expectException(ContainerException::class);
        $c->get(NeedsScalar::class);
    }

    public function testAutowiringUsesDefaultValueForScalarParam(): void
    {
        $c = new ServiceContainer();

        $obj = $c->get(NeedsDefaultScalar::class);

        $this->assertInstanceOf(NeedsDefaultScalar::class, $obj);
        $this->assertSame('default', $obj->name);
    }

    public function testAutowiringUsesDefaultValueForOptionalObjectParam(): void
    {
        $c = new ServiceContainer();

        $obj = $c->get(NeedsOptionalObject::class);

        $this->assertInstanceOf(NeedsOptionalObject::class, $obj);
        $this->assertNull($obj->bar);
    }

    public function testAutowiringResolvesNullableObjectWithoutDefault(): void
    {
        $c = new ServiceContainer();

        $obj = $c->get(NeedsNullableObjectWithoutDefault::class);

        $this->assertInstanceOf(NeedsNullableObjectWithoutDefault::class, $obj);
        $this->assertInstanceOf(Bar::class, $obj->bar);
    }

    public function testAutowiringUsesDefaultValueForOptionalScalarParam(): void
    {
        $c = new ServiceContainer();

        $obj = $c->get(NeedsOptionalScalar::class);

        $this->assertInstanceOf(NeedsOptionalScalar::class, $obj);
        $this->assertNull($obj->name);
    }


    public function testSetOverridesPreviouslyResolvedSingleton(): void
    {
        $c = new ServiceContainer();

        $first = (object)['name' => 'first'];
        $second = (object)['name' => 'second'];

        $c->set('service', static fn () => $first);
        $this->assertSame($first, $c->get('service'));

        $c->set('service', static fn () => $second);
        $this->assertSame($second, $c->get('service'));
    }

    public function testAliasOverrideInvalidatesPreviouslyResolvedAbstract(): void
    {
        $c = new ServiceContainer();

        $first = new Bar();
        $second = new Bar();

        $c->set('first', $first);
        $c->set('second', $second);
        $c->alias('abstract', 'first');
        $this->assertSame($first, $c->get('abstract'));

        $c->alias('abstract', 'second');
        $this->assertSame($second, $c->get('abstract'));
    }

    public function testTaggingAvoidsDuplicatesAndResolvesIdsAndValues(): void
    {
        $c = new ServiceContainer();
        $c->tag(Foo::class, 'handlers');
        $c->tag(Foo::class, 'handlers'); // duplicate should be ignored
        $c->tagValue('handlers', 'raw');

        $items = $c->getTagged('handlers');

        // Foo resolved once + raw value
        $this->assertCount(2, $items);
        $this->assertInstanceOf(Foo::class, $items[0]);
        $this->assertSame('raw', $items[1]);
    }


    public function testSetWithMetadataStoresDiagnosticMetadata(): void
    {
        $c = new ServiceContainer();
        $c->setWithMetadata('service', new Bar(), ['source' => 'services.php']);

        $this->assertSame(['source' => 'services.php'], $c->getMetadata('service'));
    }

    public function testAliasWithMetadataStoresDiagnosticMetadataForAbstractId(): void
    {
        $c = new ServiceContainer();
        $c->set('bar', new Bar());
        $c->aliasWithMetadata('abstract.bar', 'bar', ['source' => 'aliases.php']);

        $this->assertSame(['source' => 'aliases.php'], $c->getMetadata('abstract.bar'));
    }
}
