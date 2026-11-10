<?php
declare(strict_types=1);

namespace Winglet\Core\Support\Enum;

/**
 * Helpers for PHP native enums (UnitEnum / BackedEnum).
 *
 * This is optional. Skip entirely if you prefer using enum::cases() directly everywhere.
 */
final class EnumHelper
{
    private function __construct() {}

    /**
     * @template T of \UnitEnum
     * @param class-string<T> $enumClass
     * @return string[] list of case names
     */
    public static function names(string $enumClass): array
    {
        return array_map(static fn(\UnitEnum $c) => $c->name, $enumClass::cases());
    }

    /**
     * @template T of \BackedEnum
     * @param class-string<T> $enumClass
     * @return array<int|string> list of backed values
     */
    public static function values(string $enumClass): array
    {
        return array_map(static fn(\BackedEnum $c) => $c->value, $enumClass::cases());
    }

    /**
     * @template T of \BackedEnum
     * @param class-string<T> $enumClass
     * @return array<int|string, string> value => name map
     */
    public static function valueToNameMap(string $enumClass): array
    {
        $map = [];
        foreach ($enumClass::cases() as $c) {
            /** @var \BackedEnum $c */
            $map[$c->value] = $c->name;
        }
        return $map;
    }

    /**
     * @template T of \BackedEnum
     * @param class-string<T> $enumClass
     * @return array<string, int|string> name => value map
     */
    public static function nameToValueMap(string $enumClass): array
    {
        $map = [];
        foreach ($enumClass::cases() as $c) {
            /** @var \BackedEnum $c */
            $map[$c->name] = $c->value;
        }
        return $map;
    }

    /**
     * @template T of \BackedEnum
     * @param class-string<T> $enumClass
     * @param int|string $value
     * @return bool
     */
    public static function isValidValue(string $enumClass, int|string $value): bool
    {
        return $enumClass::tryFrom($value) !== null;
    }
}
