<?php
declare(strict_types=1);

namespace Winglet\Core\Container;

use Closure;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Minimal PSR-11 container with:
 * - explicit definitions (value or factory)
 * - lazy singletons (factory executed once, cached)
 * - autowiring fallback for concrete classes
 * - alias helper for interface -> implementation mapping
 * - tagging mechanism for collecting services by tag
 *
 * Notes:
 * - Only class-typed constructor parameters are autowired.
 * - Scalars must have defaults or be provided via explicit definitions.
 */
final class ServiceContainer implements ContainerInterface
{
    /** @var array<string,mixed> */
    private array $instances = [];

    /** @var array<string,Closure(ContainerInterface):mixed> */
    private array $factories = [];

    /** @var array<string,string> */
    private array $aliases = [];

    /** @var array<string,array<string,mixed>> */
    private array $metadata = [];

    /** @var array<string,true> */
    private array $resolving = [];

    /**
     * Tags:
     * - $tags[tag] = list of ids to resolve via container
     * - $tagValues[tag] = list of raw values (already constructed)
     *
     * @var array<string,list<string>>
     */
    private array $tags = [];

    /** @var array<string,list<mixed>> */
    private array $tagValues = [];

    /**
     * @param array<string,mixed> $definitions
     */
    public function __construct(array $definitions = [])
    {
        foreach ($definitions as $id => $def) {
            $this->set($id, $def);
        }
    }

    /**
     * Register a value or a factory.
     *
     * @param mixed $definition
     */
    public function set(string $id, mixed $definition): void
    {
        if ($id === '') {
            throw new ContainerException('Container entry id must be non-empty');
        }

        // Rebinding a service must invalidate any previously resolved singleton.
        // Otherwise configuration loaded later cannot override an earlier value/factory,
        // and get($id) may keep returning a stale instance with the correct id.
        unset($this->instances[$id], $this->factories[$id], $this->metadata[$id]);

        if ($definition instanceof Closure) {
            $this->factories[$id] = $definition;
            return;
        }

        if (is_callable($definition)) {
            $this->factories[$id] = static fn (ContainerInterface $c) => $definition($c);
            return;
        }

        $this->instances[$id] = $definition;
    }


    /**
     * Register a value or factory and attach diagnostic metadata for validation output.
     *
     * Metadata is intentionally free-form so app loaders can record source files,
     * tags, registration mode, environment, or other useful debugging details.
     *
     * @param mixed $definition
     * @param array<string,mixed> $metadata
     */
    public function setWithMetadata(string $id, mixed $definition, array $metadata): void
    {
        $this->set($id, $definition);
        $this->metadata[$id] = $metadata;
    }

    /**
     * Alias $abstract to $concrete and attach diagnostic metadata.
     *
     * @param array<string,mixed> $metadata
     */
    public function aliasWithMetadata(string $abstract, string $concrete, array $metadata): void
    {
        $this->alias($abstract, $concrete);
        $this->metadata[$abstract] = $metadata;
    }

    /**
     * Return metadata for an explicitly registered service or alias.
     *
     * @return array<string,mixed>
     */
    public function getMetadata(string $id): array
    {
        if (isset($this->metadata[$id])) {
            return $this->metadata[$id];
        }

        $resolved = $this->resolveAlias($id);
        return $this->metadata[$resolved] ?? [];
    }

    /**
     * Convenience: bind an id to a factory (lazy singleton by default).
     */
    public function bind(string $id, callable $factory): void
    {
        $this->set($id, $factory);
    }

    /**
     * Convenience: define a singleton factory explicitly (same as bind/set factory in this container).
     */
    public function singleton(string $id, callable $factory): void
    {
        $this->set($id, $factory);
    }

    /**
     * Alias $abstract to $concrete.
     *
     * Example:
     *   $c->alias(FooInterface::class, Foo::class);
     * Then:
     *   $c->get(FooInterface::class) returns $c->get(Foo::class)
     */
    public function alias(string $abstract, string $concrete): void
    {
        if ($abstract === '' || $concrete === '') {
            throw new ContainerException('Alias ids must be non-empty');
        }
        // Rebinding an alias must invalidate a previously resolved value for the abstract id.
        unset($this->instances[$abstract], $this->factories[$abstract], $this->metadata[$abstract]);
        $this->aliases[$abstract] = $concrete;
    }

    /**
     * Tag a container entry id.
     *
     * Example:
     *   $c->tag(App\Handler\FooHandler::class, 'handlers');
     *
     * Later:
     *   $handlers = $c->getTagged('handlers'); // resolves each id through container
     */
    public function tag(string $id, string $tag): void
    {
        $id = trim($id);
        $tag = trim($tag);

        if ($id === '' || $tag === '') {
            throw new ContainerException('Tag id and tag name must be non-empty');
        }

        $this->tags[$tag] ??= [];

        // avoid duplicates
        if (!in_array($id, $this->tags[$tag], true)) {
            $this->tags[$tag][] = $id;
        }
    }

    /**
     * Tag a raw value (already constructed).
     *
     * Example:
     *   $c->tagValue('handlers', new FooHandler(...));
     *
     * Use this sparingly; prefer tag() so container controls instantiation.
     */
    public function tagValue(string $tag, mixed $value): void
    {
        $tag = trim($tag);
        if ($tag === '') {
            throw new ContainerException('Tag name must be non-empty');
        }

        $this->tagValues[$tag] ??= [];
        $this->tagValues[$tag][] = $value;
    }

    /**
     * Returns resolved services/values registered under the given tag.
     *
     * - For ids tagged via tag(): resolves each id through container->get()
     * - For values tagged via tagValue(): returns values as-is
     *
     * @return list<mixed>
     */
    public function getTagged(string $tag): array
    {
        $tag = trim($tag);
        if ($tag === '') {
            throw new ContainerException('Tag name must be non-empty');
        }

        $out = [];

        foreach ($this->tags[$tag] ?? [] as $id) {
            $out[] = $this->get($id);
        }

        foreach ($this->tagValues[$tag] ?? [] as $v) {
            $out[] = $v;
        }

        return $out;
    }

    /**
     * Returns tagged services, validated to implement/extend $interface.
     *
     * Use-case:
     * - Event handlers, middleware extensions, command handlers, etc.
     *
     * Example:
     *   $handlers = $c->getTaggedAs('handlers', HandlerInterface::class);
     *
     * If a tagged item does not match, throws ContainerException with details for logs.
     *
     * @template T of object
     * @param class-string<T> $interface
     * @return list<T>
     */
    public function getTaggedAs(string $tag, string $interface): array
    {
        $tag = trim($tag);
        $interface = trim($interface);

        if ($tag === '') {
            throw new ContainerException('Tag name must be non-empty');
        }
        if ($interface === '') {
            throw new ContainerException('Interface/class name must be non-empty');
        }
        if (!interface_exists($interface) && !class_exists($interface)) {
            throw new ContainerException("Interface/class does not exist: {$interface}");
        }

        $items = $this->getTagged($tag);
        $out = [];

        foreach ($items as $i => $item) {
            if (!is_object($item)) {
                throw new ContainerException("Tagged item is not an object", [
                    'tag' => $tag,
                    'expected' => $interface,
                    'index' => $i,
                    'got_type' => gettype($item),
                ]);
            }

            if (!$item instanceof $interface) {
                throw new ContainerException("Tagged item does not implement expected type", [
                    'tag' => $tag,
                    'expected' => $interface,
                    'index' => $i,
                    'got' => $item::class,
                ]);
            }

            $out[] = $item;
        }

        /** @var list<T> $out */
        return $out;
    }

    /**
     * Returns tagged ids (without resolving). Useful for debugging / introspection.
     *
     * @return list<string>
     */
    public function getTaggedIds(string $tag): array
    {
        $tag = trim($tag);
        if ($tag === '') {
            throw new ContainerException('Tag name must be non-empty');
        }
        return $this->tags[$tag] ?? [];
    }

    public function has(string $id): bool
    {
        $id = $this->resolveAlias($id);

        return array_key_exists($id, $this->instances)
            || isset($this->factories[$id])
            || class_exists($id);
    }

    public function get(string $id): mixed
    {
        $id = $this->resolveAlias($id);

        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (isset($this->resolving[$id])) {
            throw new ContainerException("Circular dependency detected while resolving: {$id}");
        }

        $this->resolving[$id] = true;
        try {
            if (isset($this->factories[$id])) {
                $value = ($this->factories[$id])($this);
                $this->instances[$id] = $value;
                unset($this->factories[$id]);
                return $value;
            }

            // Autowire concrete classes
            if (class_exists($id)) {
                $obj = $this->autowire($id);
                $this->instances[$id] = $obj;
                return $obj;
            }

            throw new NotFoundException("Container entry not found: {$id}");
        } finally {
            unset($this->resolving[$id]);
        }
    }

    private function resolveAlias(string $id): string
    {
        // Resolve alias chain safely
        $seen = [];
        while (isset($this->aliases[$id])) {
            if (isset($seen[$id])) {
                throw new ContainerException("Circular alias detected for id: {$id}");
            }
            $seen[$id] = true;
            $id = $this->aliases[$id];
        }
        return $id;
    }

    /**
     * @phpstan-param class-string $class
     */
    private function autowire(string $class): object
    {
        if (!class_exists($class)) {
            throw new ContainerException("Class does not exist: {$class}");
        }
        $rc = new ReflectionClass($class);
        if (!$rc->isInstantiable()) {
            throw new ContainerException("Class is not instantiable: {$class}");
        }

        $ctor = $rc->getConstructor();
        if ($ctor === null) {
            return $rc->newInstance();
        }

        $args = [];
        foreach ($ctor->getParameters() as $p) {
            $type = $p->getType();

            // If a parameter has a default value, prefer it.
            // This is important for optional dependencies like `?Foo $foo = null`.
            // Autowiring such params would force-resolve Foo even though the class
            // intentionally allows omission (and may not be autowirable).
            if ($p->isDefaultValueAvailable()) {
                $args[] = $p->getDefaultValue();
                continue;
            }

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $dep = $type->getName();
                $args[] = $this->get($dep);
                continue;
            }

            throw new ContainerException(
                "Unable to autowire '{$class}': parameter '{$p->getName()}' must be a class type or have a default value"
            );
        }

        return $rc->newInstanceArgs($args);
    }
}