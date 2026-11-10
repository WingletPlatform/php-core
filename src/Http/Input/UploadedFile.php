<?php
declare(strict_types=1);

namespace Winglet\Core\Http\Input;

/**
 * Lightweight wrapper around a single PHP uploaded file entry.
 *
 * Note: Winglet\Core\Http\Request currently doesn't capture $_FILES.
 * This wrapper is produced by InputReader directly from $_FILES.
 */
final class UploadedFile
{
    public function __construct(
        public readonly string $field,
        public readonly string $originalName,
        public readonly string $tmpName,
        public readonly int $size,
        public readonly int $error,
        public readonly ?string $mimeType = null,
    ) {}

    public function isOk(): bool
    {
        return $this->error === UPLOAD_ERR_OK;
    }

    public function extension(): string
    {
        $p = strrpos($this->originalName, '.');
        return $p === false ? '' : strtolower(substr($this->originalName, $p + 1));
    }
}
