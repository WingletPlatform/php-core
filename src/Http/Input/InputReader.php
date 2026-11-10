<?php
declare(strict_types=1);

namespace Winglet\Core\Http\Input;

use Winglet\Core\Http\Request;

/**
 * Reads raw input values with required precedence:
 * 1) POST, 2) GET, 3) JSON body
 * and supports uploaded files via $_FILES.
 */
final class InputReader
{
    /** @var array<string,mixed> */
    private array $json;

    public function __construct(private Request $request)
    {
        $this->json = $request->json ?? [];
    }

    public function has(string $name): bool
    {
        if (array_key_exists($name, $this->request->post)) return true;
        if (array_key_exists($name, $this->request->query)) return true;
        if (array_key_exists($name, $this->json)) return true;
        return $this->hasFile($name);
    }

    public function get(string $name): mixed
    {
        if (array_key_exists($name, $this->request->post)) return $this->request->post[$name];
        if (array_key_exists($name, $this->request->query)) return $this->request->query[$name];
        if (array_key_exists($name, $this->json)) return $this->json[$name];
        $file = $this->getFile($name);
        return $file;
    }

    /**
     * @param list<string> $names
     * @return array<string,bool>
     */
    public function presentMap(array $names): array
    {
        $out = [];
        foreach ($names as $n) {
            if ($n === '') continue;
            if (!$this->has($n)) {
                $out[$n] = false;
                continue;
            }
            $v = $this->get($n);
            // Treat null as not present. Treat empty string as present (caller can validate if needed).
            $out[$n] = ($v !== null);
        }
        return $out;
    }

    private function hasFile(string $name): bool
    {
        return isset($this->request->files[$name]) || isset($_FILES[$name]);
    }

    private function getFile(string $name): ?UploadedFile
    {
        $file = $this->request->files[$name] ?? ($_FILES[$name] ?? null);
        if (!is_array($file)) return null;
        $f = $file;

        // Single-file only (multi-file could be added later)
        $orig = is_string($f['name'] ?? null) ? $f['name'] : '';
        $tmp = is_string($f['tmp_name'] ?? null) ? $f['tmp_name'] : '';
        $size = is_numeric($f['size'] ?? null) ? (int)$f['size'] : 0;
        $err = is_numeric($f['error'] ?? null) ? (int)$f['error'] : UPLOAD_ERR_NO_FILE;
        $type = is_string($f['type'] ?? null) ? $f['type'] : null;

        return new UploadedFile(
            field: $name,
            originalName: $orig,
            tmpName: $tmp,
            size: $size,
            error: $err,
            mimeType: $type
        );
    }
}