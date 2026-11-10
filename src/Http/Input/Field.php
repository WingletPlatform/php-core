<?php
declare(strict_types=1);

namespace Winglet\Core\Http\Input;

/**
 * Field definition (type + optional converters/validators).
 */
final class Field
{
    /**
     * @param null|\Closure(mixed, FieldContext):mixed $converter
     * @param list<\Closure(mixed, FieldContext):null|string> $validators
     * @param list<string> $datetimeFormats
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type = 'string',
        public readonly bool $required = false,
        public readonly bool $nullable = false,

        // Stored as Closure to keep property typing strict and predictable
        public readonly ?\Closure $converter = null,

        public readonly array $validators = [],

        /** @var null|Field Item schema for array types */
        public readonly ?Field $items = null,

        /** @var null|Field Key schema for map types */
        public readonly ?Field $key = null,

        /** @var null|Field Value schema for map types */
        public readonly ?Field $value = null,

        public readonly array $datetimeFormats = [],
    ) {}

    /** @param array<string,mixed> $opts */
    public static function required(string $name, string $type = 'string', array $opts = []): self
    {
        return self::make($name, $type, true, $opts);
    }

    /** @param array<string,mixed> $opts */
    public static function optional(string $name, string $type = 'string', array $opts = []): self
    {
        return self::make($name, $type, false, $opts);
    }

    /** @param array<string,mixed> $opts */
    private static function make(string $name, string $type, bool $required, array $opts): self
    {
        $nullable = (bool)($opts['nullable'] ?? false);

        $converter = $opts['converter'] ?? null;
        if ($converter !== null) {
            if (!is_callable($converter)) {
                throw new \InvalidArgumentException("converter must be callable for {$name}");
            }
            // Normalize to Closure so the typed property remains strict.
            $converter = \Closure::fromCallable($converter);
        } else {
            $converter = null;
        }

        $validators = $opts['validators'] ?? ($opts['validator'] ?? []);
        if (is_callable($validators)) {
            $validators = [$validators];
        }
        if (!is_array($validators)) {
            $validators = [];
        }

        $validators = array_values(array_filter($validators, fn($v) => is_callable($v)));

        // Normalize validators to Closure so InputValidator can safely invoke them
        $validators = array_map(fn($v) => \Closure::fromCallable($v), $validators);

        $items = $opts['items'] ?? null;
        $key = $opts['key'] ?? null;
        $value = $opts['value'] ?? null;

        $datetimeFormats = $opts['datetime_formats'] ?? [];
        if (!is_array($datetimeFormats)) $datetimeFormats = [];
        $datetimeFormats = array_values(array_filter($datetimeFormats, fn($x) => is_string($x) && $x !== ''));

        return new self(
            name: $name,
            type: $type,
            required: $required,
            nullable: $nullable,
            converter: $converter,
            validators: $validators,
            items: ($items instanceof self) ? $items : null,
            key: ($key instanceof self) ? $key : null,
            value: ($value instanceof self) ? $value : null,
            datetimeFormats: $datetimeFormats,
        );
    }
}