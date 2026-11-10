<?php
declare(strict_types=1);

namespace Winglet\Core\Http\Input;

use DateTimeImmutable;
use DateTimeZone;
use Winglet\Core\Http\Request;

/**
 * Optional input helper for controllers.
 *
 * Features:
 * - Required/optional fields
 * - Presence rules (a AND (b OR (c AND d ...)))
 * - Type conversion + custom converters
 * - Custom validation callbacks
 * - Array and key-value map support
 * - File upload support (UploadedFile)
 * - Value sources: POST, GET, then JSON body
 */
final class InputValidator
{
    /** @var array<string,Field> */
    private array $fields = [];

    private ?Requirement $requirement = null;

    /** @var array<string,mixed> */
    private array $values = [];

    /** @var list<array<string,mixed>> */
    private array $errors = [];

    private bool $validated = false;

    private function __construct(
        private Request $request
    ) {}

    public static function from(Request $request): self
    {
        return new self($request);
    }

    /**
     * Define a required field.
     *
     * @param array<string,mixed> $opts
     */
    public function required(string $name, string $type = 'string', array $opts = []): self
    {
        $this->fields[$name] = Field::required($name, $type, $opts);
        return $this;
    }

    /**
     * Define an optional field.
     *
     * @param array<string,mixed> $opts
     */
    public function optional(string $name, string $type = 'string', array $opts = []): self
    {
        $this->fields[$name] = Field::optional($name, $type, $opts);
        return $this;
    }

    /**
     * Add a presence rule (independent from required/optional flags).
     */
    public function requireRule(Requirement $requirement): self
    {
        $this->requirement = $requirement;
        return $this;
    }

    /**
     * Validate and throw InputValidationException on failure.
     */
    public function validate(): self
    {
        if ($this->validated) return $this;
        $this->validated = true;

        $reader = new InputReader($this->request);

        $this->errors = [];
        $this->values = [];

        // 1) required fields missing
        foreach ($this->fields as $name => $field) {
            if ($field->required && !$reader->has($name)) {
                $this->errors[] = [
                    'field' => $name,
                    'code' => 'missing',
                    'message' => 'Field is required',
                ];
            }
        }

        // 2) requirement rule
        if ($this->requirement !== null) {
            $present = $reader->presentMap(array_keys($this->fields));
            if (!$this->requirement->isSatisfied($present)) {
                $this->errors[] = [
                    'field' => '__rule__',
                    'code' => 'rule_failed',
                    'message' => 'Required field combination missing',
                    'missing' => $this->requirement->missing($present),
                ];
            }
        }

        // 3) conversions + validations
        foreach ($this->fields as $name => $field) {
            if (!$reader->has($name)) {
                continue;
            }

            $raw = $reader->get($name);

            // Optional not provided or null => ok, do not fail
            if ($raw === null) {
                continue;
            }

            try {
                $value = $this->convert($field, $raw);
            } catch (\Throwable) {
                $this->errors[] = [
                    'field' => $name,
                    'code' => 'invalid_format',
                    'message' => 'Invalid field format',
                ];
                continue;
            }

            if ($value === null) {
                if ($field->required && !$field->nullable) {
                    $this->errors[] = [
                        'field' => $name,
                        'code' => 'missing',
                        'message' => 'Field is required',
                    ];
                }
                continue;
            }

            // validators
            $ctx = new FieldContext($name, $this->values);
            foreach ($field->validators as $v) {
                $msg = null;
                try {
                    $msg = $v($value, $ctx);
                } catch (\Throwable) {
                    $msg = 'Invalid value';
                }

                if (is_string($msg) && $msg !== '') {
                    $this->errors[] = [
                        'field' => $name,
                        'code' => 'invalid',
                        'message' => $msg,
                    ];
                }
            }

            $this->values[$name] = $value;
        }

        if ($this->errors !== []) {
            throw new InputValidationException($this->errors);
        }

        return $this;
    }

    public function get(string $name, mixed $default = null): mixed
    {
        if (!$this->validated) {
            $this->validate();
        }
        return $this->values[$name] ?? $default;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        if (!$this->validated) {
            $this->validate();
        }
        return $this->values;
    }

    // ---------------------------------------------------------------------
    // Conversion
    // ---------------------------------------------------------------------

    private function convert(Field $field, mixed $raw): mixed
    {
        // custom converter first
        if ($field->converter !== null) {
            $ctx = new FieldContext($field->name, $this->values);
            return ($field->converter)($raw, $ctx);
        }

        $type = strtolower($field->type);

        // optional null
        if ($raw === null) return null;

        return match ($type) {
            'string' => $this->toString($raw),
            'int', 'integer' => $this->toInt($raw),
            'float', 'decimal' => $this->toFloat($raw),
            'bool', 'boolean' => $this->toBool($raw),
            'datetime' => $this->toDateTime($raw, $field->datetimeFormats),
            'array' => $this->toArray($raw, $field->items),
            'map', 'keyvalue', 'key-value' => $this->toMap($raw, $field->key, $field->value),
            'file', 'upload' => $this->toFile($raw),
            default => throw new \InvalidArgumentException("Unsupported type: {$field->type}"),
        };
    }

    private function toString(mixed $raw): string
    {
        if (is_string($raw)) return $raw;
        if (is_int($raw) || is_float($raw) || is_bool($raw)) return (string)$raw;
        if ($raw instanceof DateTimeImmutable) return $raw->format(DateTimeImmutable::ATOM);
        throw new \InvalidArgumentException('Not convertible to string');
    }

    private function toInt(mixed $raw): int
    {
        if (is_int($raw)) return $raw;
        if (is_bool($raw)) return $raw ? 1 : 0;
        if (is_float($raw)) return (int)round($raw);

        if (is_string($raw)) {
            $s = trim($raw);
            if ($s === '') throw new \InvalidArgumentException('Empty');
            // Allow decimal separators . or ,; accept float-like and round
            $s = str_replace(',', '.', $s);
            if (!preg_match('/^-?\d+(\.\d+)?$/', $s)) {
                throw new \InvalidArgumentException('Invalid integer');
            }
            return (int)round((float)$s);
        }

        throw new \InvalidArgumentException('Not convertible to int');
    }

    private function toFloat(mixed $raw): float
    {
        if (is_float($raw)) return $raw;
        if (is_int($raw)) return (float)$raw;
        if (is_bool($raw)) return $raw ? 1.0 : 0.0;

        if (is_string($raw)) {
            $s = trim($raw);
            if ($s === '') throw new \InvalidArgumentException('Empty');
            $s = str_replace(',', '.', $s);
            if (!preg_match('/^-?\d+(\.\d+)?$/', $s)) {
                throw new \InvalidArgumentException('Invalid float');
            }
            return (float)$s;
        }

        throw new \InvalidArgumentException('Not convertible to float');
    }

    private function toBool(mixed $raw): bool
    {
        if (is_bool($raw)) return $raw;
        if (is_int($raw)) return $raw !== 0;
        if (is_float($raw)) return $raw != 0.0;

        if (is_string($raw)) {
            $s = strtolower(trim($raw));
            if (in_array($s, ['1', 'true', 'yes', 'on'], true)) return true;
            if (in_array($s, ['0', 'false', 'no', 'off'], true)) return false;
            throw new \InvalidArgumentException('Invalid bool');
        }

        throw new \InvalidArgumentException('Not convertible to bool');
    }

    /** @param list<string> $formats */
    private function toDateTime(mixed $raw, array $formats): DateTimeImmutable
    {
        if ($raw instanceof DateTimeImmutable) return $raw;
        if (is_int($raw)) {
            return (new DateTimeImmutable('@' . $raw))->setTimezone(new DateTimeZone(date_default_timezone_get()));
        }
        if (is_float($raw)) {
            return (new DateTimeImmutable('@' . (int)round($raw)))->setTimezone(new DateTimeZone(date_default_timezone_get()));
        }

        if (is_string($raw)) {
            $s = trim($raw);
            if ($s === '') throw new \InvalidArgumentException('Empty');

            // Try explicit formats first
            foreach ($formats as $f) {
                $dt = DateTimeImmutable::createFromFormat($f, $s);
                if ($dt instanceof DateTimeImmutable) return $dt;
            }

            // Fallback to PHP parser
            return new DateTimeImmutable($s);
        }

        throw new \InvalidArgumentException('Not convertible to datetime');
    }

    /**
     * @return list<mixed>
     */
    private function toArray(mixed $raw, ?Field $items): array
    {
        $arr = null;

        if (is_array($raw)) {
            $arr = $raw;
        } elseif (is_string($raw)) {
            $s = trim($raw);
            if ($s === '') return [];

            // JSON array/object string
            if ((str_starts_with($s, '[') && str_ends_with($s, ']')) || (str_starts_with($s, '{') && str_ends_with($s, '}'))) {
                $decoded = json_decode($s, true);
                if (is_array($decoded)) {
                    $arr = $decoded;
                }
            }

            if ($arr === null) {
                // Comma-separated fallback
                $arr = array_map('trim', explode(',', $s));
            }
        } else {
            throw new \InvalidArgumentException('Not convertible to array');
        }

        // If items schema specified, convert each value
        if ($items instanceof Field) {
            $out = [];
            foreach (array_values($arr) as $i => $v) {
                try {
                    $out[] = $this->convert($items, $v);
                } catch (\Throwable) {
                    throw new \InvalidArgumentException("Invalid array item at index {$i}");
                }
            }
            return $out;
        }

        return array_values($arr);
    }

    /**
     * @return array<string|int, mixed>
     */
    private function toMap(mixed $raw, ?Field $keySchema, ?Field $valueSchema): array
    {
        if (is_string($raw)) {
            $s = trim($raw);
            if ($s === '') return [];
            $decoded = json_decode($s, true);
            if (!is_array($decoded)) throw new \InvalidArgumentException('Invalid map JSON');
            $raw = $decoded;
        }

        if (!is_array($raw)) {
            throw new \InvalidArgumentException('Not convertible to map');
        }

        $out = [];
        foreach ($raw as $k => $v) {
            $kk = $k;
            if ($keySchema instanceof Field) {
                $kk = $this->convert($keySchema, $k);
            }
            $vv = $v;
            if ($valueSchema instanceof Field) {
                $vv = $this->convert($valueSchema, $v);
            }
            $out[$kk] = $vv;
        }

        return $out;
    }

    private function toFile(mixed $raw): UploadedFile
    {
        if ($raw instanceof UploadedFile) {
            if (!$raw->isOk()) {
                throw new \InvalidArgumentException('Upload error');
            }
            return $raw;
        }
        throw new \InvalidArgumentException('Not a file upload');
    }
}