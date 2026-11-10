<?php
declare(strict_types=1);

namespace Winglet\Core\Doctrine\Entity;

use Doctrine\Common\Collections\Collection;
use Psr\Log\LoggerInterface;
use UnitEnum;

/**
 * Base entity helper for exporting entities as arrays (API output).
 *
 * Features:
 * - include/deep include support
 * - recursion protection
 * - optional enum export shape (value or object)
 */
abstract class AbstractEntity
{
    /** @var array<string,bool> */
    private static array $missingExportLogged = [];

    private static ?LoggerInterface $exportLogger = null;

    public static function setExportLogger(?LoggerInterface $logger): void
    {
        self::$exportLogger = $logger;
    }

    /** @var int|null */
    protected ?int $exportMaxDepth = null;

    /** @var list<string> */
    protected array $alwaysDeepExport = [];

    /**
     * Export entity as array.
     *
     * @param array<int|string, mixed> $include
     */
    public function exportArray(array $include = [], bool $enumAsObject = false): array
    {
        $includeMap = self::normalizeInclude($include);

        $out = [];
        $visited = [];

        foreach ($this->exportableProperties() as $propName) {
            $out[$propName] = $this->exportValue(
                propName: $propName,
                includeMap: $includeMap,
                visited: $visited,
                depth: 0,
                enumAsObject: $enumAsObject
            );
        }

        return $out;
    }

    /**
     * Override in entity to define what gets exported.
     *
     * @return list<string>
     */
    protected function exportableProperties(): array
    {
        $rc = new \ReflectionClass($this);
        $props = [];
        foreach ($rc->getProperties(\ReflectionProperty::IS_PUBLIC) as $p) {
            $props[] = $p->getName();
        }
        return $props;
    }

    /**
     * @param array<string, array<string, mixed>> $includeMap
     * @param array<int, bool> $visited
     */
    private function exportValue(
        string $propName,
        array $includeMap,
        array &$visited,
        int $depth,
        bool $enumAsObject
    ): mixed {
        if (!$this->hasReadableProperty($propName)) {
            return null;
        }

        $value = $this->readPropertyValue($propName);

        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if ($value instanceof UnitEnum) {
            if ($enumAsObject) {
                return [
                    'name' => $value->name,
                    'value' => ($value instanceof \BackedEnum) ? $value->value : $value->name,
                ];
            }
            return ($value instanceof \BackedEnum) ? $value->value : $value->name;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        if (is_array($value) || $value instanceof Collection) {
            $items = [];

            $iter = $value instanceof Collection ? $value->toArray() : $value;

            foreach ($iter as $k => $v) {
                if ($v === null || is_scalar($v)) {
                    $items[$k] = $v;
                    continue;
                }

                if ($v instanceof UnitEnum) {
                    $items[$k] = $enumAsObject
                        ? [
                            'name' => $v->name,
                            'value' => ($v instanceof \BackedEnum) ? $v->value : $v->name,
                        ]
                        : (($v instanceof \BackedEnum) ? $v->value : $v->name);
                    continue;
                }

                if ($v instanceof \DateTimeInterface) {
                    $items[$k] = $v->format(\DateTimeInterface::ATOM);
                    continue;
                }

                if (is_object($v)) {
                    if (!$this->shouldDeepExport($propName, $includeMap, $depth)) {
                        $items[$k] = null;
                        continue;
                    }

                    $subInclude = $this->subIncludeFor($propName, $includeMap);

                    if (method_exists($v, 'exportArray')) {
                        $items[$k] = $this->exportObject($propName, $v, $subInclude, $visited, $depth + 1, $enumAsObject);
                        continue;
                    }

                    $this->logMissingExportArrayOnce($propName, $v);
                    $items[$k] = null;
                    continue;
                }

                $items[$k] = null;
            }

            return $items;
        }

        if (is_object($value)) {
            if (!$this->shouldDeepExport($propName, $includeMap, $depth)) {
                return null;
            }

            $subInclude = $this->subIncludeFor($propName, $includeMap);

            if (method_exists($value, 'exportArray')) {
                return $this->exportObject($propName, $value, $subInclude, $visited, $depth + 1, $enumAsObject);
            }

            $this->logMissingExportArrayOnce($propName, $value);
            return null;
        }

        return null;
    }

    private function hasReadableProperty(string $propName): bool
    {
        $getter = 'get' . ucfirst($propName);
        if (method_exists($this, $getter)) {
            return true;
        }

        $isser = 'is' . ucfirst($propName);
        if (method_exists($this, $isser)) {
            return true;
        }

        $rc = new \ReflectionClass($this);
        while ($rc) {
            if ($rc->hasProperty($propName)) {
                return true;
            }
            $rc = $rc->getParentClass();
        }

        return false;
    }

    private function readPropertyValue(string $propName): mixed
    {
        $getter = 'get' . ucfirst($propName);
        if (method_exists($this, $getter)) {
            return $this->{$getter}();
        }

        $isser = 'is' . ucfirst($propName);
        if (method_exists($this, $isser)) {
            return $this->{$isser}();
        }

        $rc = new \ReflectionClass($this);
        while ($rc) {
            if ($rc->hasProperty($propName)) {
                $rp = $rc->getProperty($propName);
                $rp->setAccessible(true);
                return $rp->getValue($this);
            }
            $rc = $rc->getParentClass();
        }

        return null;
    }

    /**
     * @param array<string, array<string, mixed>> $subInclude
     * @param array<int, bool> $visited
     */
    private function exportObject(
        string $propName,
        object $obj,
        array $subInclude,
        array &$visited,
        int $depth,
        bool $enumAsObject
    ): mixed {
        if ($this->exportMaxDepth !== null && $depth > $this->exportMaxDepth) {
            return null;
        }

        $oid = spl_object_id($obj);
        if (isset($visited[$oid])) {
            return null;
        }

        $visited[$oid] = true;

        try {
            /** @var callable $call */
            $call = [$obj, 'exportArray'];
            return $call($subInclude, $enumAsObject);
        } finally {
            unset($visited[$oid]);
        }
    }

    /** @param array<string, array<string, mixed>> $includeMap */
    private function shouldDeepExport(string $propName, array $includeMap, int $depth): bool
    {
        if ($this->exportMaxDepth !== null && $depth >= $this->exportMaxDepth) {
            return false;
        }

        if (in_array($propName, $this->alwaysDeepExport, true)) {
            return true;
        }

        return array_key_exists($propName, $includeMap);
    }

    /**
     * @param array<string, array<string, mixed>> $includeMap
     * @return array<string, mixed>
     */
    private function subIncludeFor(string $propName, array $includeMap): array
    {
        return $includeMap[$propName] ?? [];
    }

    /**
     * @param array<int|string, mixed> $include
     * @return array<string, array<string, mixed>>
     */
    private static function normalizeInclude(array $include): array
    {
        $map = [];

        foreach ($include as $k => $v) {
            if (is_int($k)) {
                // Numeric include list: ['foo', 'bar']
                if (is_string($v)) {
                    $map[$v] = [];
                }
                continue;
            }

            // String key include map: ['relation' => [...]]
            if (is_array($v)) {
                $map[$k] = self::normalizeInclude($v);
            } elseif (is_string($v)) {
                $map[$k] = self::normalizeInclude([$v]);
            } else {
                $map[$k] = [];
            }
        }

        return $map;
    }

    private function logMissingExportArrayOnce(string $propName, object $target): void
    {
        $sourceClass = static::class;
        $targetClass = $target::class;

        $key = $sourceClass . '|' . $propName . '|' . $targetClass;
        if (isset(self::$missingExportLogged[$key])) {
            return;
        }
        self::$missingExportLogged[$key] = true;

        $msg = sprintf(
            'Entity export failed: tried to deep-export relation "%s" from "%s" but target "%s" has no exportArray(). Did you forget to extend AbstractEntity?',
            $propName,
            $sourceClass,
            $targetClass
        );

        // Prefer logging if app provides a logger, otherwise fall back to PHP warning.
        if (self::$exportLogger) {
            self::$exportLogger->warning($msg);
            return;
        }

        trigger_error($msg, E_USER_WARNING);
    }
}