<?php
declare(strict_types=1);

namespace Winglet\Core\Http\Input;

/**
 * Ready-to-use validator factories for InputValidator fields.
 *
 * Each factory returns callable(mixed $value, FieldContext $ctx): null|string
 * - return null => ok
 * - return string => validation error message
 */
final class Validators
{
    /**
     * Value must not be null / empty string.
     * Note: presence is handled by InputValidator required(), this is for "must not be empty".
     */
    public static function notEmpty(string $message = 'Must not be empty'): callable
    {
        return static function (mixed $value, FieldContext $ctx) use ($message): ?string {
            if ($value === null) return $message;
            if (is_string($value) && trim($value) === '') return $message;
            if (is_array($value) && $value === []) return $message;
            return null;
        };
    }

    public static function min(int|float $min, ?string $message = null): callable
    {
        $message ??= "Must be at least {$min}";
        return static function (mixed $value, FieldContext $ctx) use ($min, $message): ?string {
            if (is_int($value) || is_float($value)) {
                return ($value < $min) ? $message : null;
            }
            if (is_string($value) && is_numeric(str_replace(',', '.', trim($value)))) {
                $n = (float)str_replace(',', '.', trim($value));
                return ($n < $min) ? $message : null;
            }
            return 'Invalid number';
        };
    }

    public static function max(int|float $max, ?string $message = null): callable
    {
        $message ??= "Must be at most {$max}";
        return static function (mixed $value, FieldContext $ctx) use ($max, $message): ?string {
            if (is_int($value) || is_float($value)) {
                return ($value > $max) ? $message : null;
            }
            if (is_string($value) && is_numeric(str_replace(',', '.', trim($value)))) {
                $n = (float)str_replace(',', '.', trim($value));
                return ($n > $max) ? $message : null;
            }
            return 'Invalid number';
        };
    }

    public static function between(int|float $min, int|float $max, ?string $message = null): callable
    {
        $message ??= "Must be between {$min} and {$max}";
        return static function (mixed $value, FieldContext $ctx) use ($min, $max, $message): ?string {
            $n = null;

            if (is_int($value) || is_float($value)) {
                $n = (float)$value;
            } elseif (is_string($value)) {
                $s = str_replace(',', '.', trim($value));
                if ($s !== '' && is_numeric($s)) $n = (float)$s;
            }

            if ($n === null) return 'Invalid number';
            return ($n < $min || $n > $max) ? $message : null;
        };
    }

    public static function minLen(int $min, ?string $message = null): callable
    {
        $message ??= "Must be at least {$min} characters";
        return static function (mixed $value, FieldContext $ctx) use ($min, $message): ?string {
            if (!is_string($value)) return 'Invalid string';
            return (mb_strlen($value) < $min) ? $message : null;
        };
    }

    public static function maxLen(int $max, ?string $message = null): callable
    {
        $message ??= "Must be at most {$max} characters";
        return static function (mixed $value, FieldContext $ctx) use ($max, $message): ?string {
            if (!is_string($value)) return 'Invalid string';
            return (mb_strlen($value) > $max) ? $message : null;
        };
    }

    public static function regex(string $pattern, ?string $message = null): callable
    {
        $message ??= 'Invalid format';
        return static function (mixed $value, FieldContext $ctx) use ($pattern, $message): ?string {
            if (!is_string($value)) return 'Invalid string';
            $ok = @preg_match($pattern, $value);
            if ($ok !== 1) return $message;
            return null;
        };
    }

    /**
     * @param list<string|int> $allowed
     */
    public static function enum(array $allowed, ?string $message = null, bool $strict = true): callable
    {
        $message ??= 'Invalid value';
        return static function (mixed $value, FieldContext $ctx) use ($allowed, $message, $strict): ?string {
            foreach ($allowed as $a) {
                if ($strict) {
                    if ($value === $a) return null;
                } else {
                    if ((string)$value === (string)$a) return null;
                }
            }
            return $message;
        };
    }

    public static function email(?string $message = null): callable
    {
        $message ??= 'Invalid email';
        return static function (mixed $value, FieldContext $ctx) use ($message): ?string {
            if (!is_string($value)) return 'Invalid string';
            return (filter_var($value, FILTER_VALIDATE_EMAIL) === false) ? $message : null;
        };
    }

    public static function uuid(?string $message = null): callable
    {
        $message ??= 'Invalid UUID';
        return static function (mixed $value, FieldContext $ctx) use ($message): ?string {
            if (!is_string($value)) return 'Invalid string';
            $v = strtolower(trim($value));
            // UUID v1-v5 (simple)
            return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $v) === 1
                ? null
                : $message;
        };
    }

    public static function fileMaxSize(int $bytes, ?string $message = null): callable
    {
        $message ??= "File too large (max {$bytes} bytes)";
        return static function (mixed $value, FieldContext $ctx) use ($bytes, $message): ?string {
            if (!$value instanceof UploadedFile) return 'Invalid file';
            if (!$value->isOk()) return 'Upload error';
            return ($value->size > $bytes) ? $message : null;
        };
    }

    /**
     * @param list<string> $allowedMimeTypes
     */
    public static function fileMime(array $allowedMimeTypes, ?string $message = null): callable
    {
        $message ??= 'Invalid file type';
        $allowedMimeTypes = array_values(array_filter($allowedMimeTypes, fn(string $x): bool => $x !== ''));

        return static function (mixed $value, FieldContext $ctx) use ($allowedMimeTypes, $message): ?string {
            if (!$value instanceof UploadedFile) return 'Invalid file';
            if (!$value->isOk()) return 'Upload error';

            $mime = $value->mimeType ?? '';
            if ($mime === '') return $message;

            foreach ($allowedMimeTypes as $a) {
                if (hash_equals($a, $mime)) return null;
            }

            return $message;
        };
    }

    /**
     * @param list<string> $allowedExts e.g. ['jpg','png','pdf']
     */
    public static function fileExtension(array $allowedExts, ?string $message = null): callable
    {
        $message ??= 'Invalid file extension';
        $allowedExts = array_values(array_filter($allowedExts, fn(string $x): bool => $x !== ''));
        $allowedExts = array_map(fn(string $x): string => strtolower(ltrim($x, '.')), $allowedExts);

        return static function (mixed $value, FieldContext $ctx) use ($allowedExts, $message): ?string {
            if (!$value instanceof UploadedFile) return 'Invalid file';
            if (!$value->isOk()) return 'Upload error';

            $ext = $value->extension();
            if ($ext === '') return $message;

            foreach ($allowedExts as $a) {
                if (hash_equals($a, $ext)) return null;
            }
            return $message;
        };
    }
}
