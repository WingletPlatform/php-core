<?php
declare(strict_types=1);

namespace Winglet\Core\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

final class ArrayLogger extends AbstractLogger
{
    /** @var list<array{level:string,message:string,context:array<string,mixed>}> */
    public array $records = [];

    /** @param array<string,mixed> $context */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string)$level,
            'message' => (string)$message,
            'context' => $context,
        ];
    }

    /** @return array{level:string,message:string,context:array<string,mixed>} */
    public function lastRecord(): array
    {
        if ($this->records === []) {
            throw new \RuntimeException('No log records available.');
        }

        return $this->records[array_key_last($this->records)];
    }
}
