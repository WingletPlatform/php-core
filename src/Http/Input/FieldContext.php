<?php
declare(strict_types=1);

namespace Winglet\Core\Http\Input;

/**
 * Validation context passed to converters/validators.
 */
final class FieldContext
{
    /** @param array<string,mixed> $all */
    public function __construct(
        public readonly string $field,
        public readonly array $all,
    ) {}
}
