<?php
declare(strict_types=1);

/**
 * Framework HTTP response model.
 *
 * Responsibilities:
 * - Stores status code, headers, and body string.
 * - Provides Response::json() helper for JSON responses.
 * - Provides Response::raw() and Response::download() helpers for non-JSON responses.
 * - Sends headers/status/body to PHP output via send().
 *
 * SECURITY:
 * - JSON encoding failures throw an exception that will be handled by ExceptionHandler
 *   (client receives a generic error; full details are logged).
 * - Response headers are validated to prevent header injection.
 */

namespace Winglet\Core\Http;

final class Response
{
    /**
     * @param array<string,string> $headers
     */
    private function __construct(
        public int $status,
        public array $headers,
        public string $body
    ) {}

    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $headers
     */
    public static function json(array $data, int $status = 200, array $headers = []): self
    {
        $headers = array_merge(['Content-Type' => 'application/json; charset=utf-8'], $headers);

        try {
            $body = json_encode(
                $data,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $e) {
            // Do not attempt to recover here; let the global error handler log and respond safely.
            throw new \RuntimeException('Failed to encode JSON response', 0, $e);
        }

        return new self($status, self::normalizeHeaders($headers), $body);
    }

    /**
     * Build a complete non-JSON HTTP response.
     *
     * The body is sent exactly as provided. It is not JSON encoded and it is not
     * wrapped in any API envelope. This keeps raw/file responses inside the
     * normal framework lifecycle: middleware, authorization, error handling and
     * access logging still operate around the returned Response object.
     *
     * @param array<string,string> $headers
     */
    public static function raw(string $body, int $status = 200, array $headers = []): self
    {
        return new self($status, self::normalizeHeaders($headers), $body);
    }

    /**
     * Build a downloadable or inline file response from an in-memory string.
     *
     * @param array<string,string> $headers
     */
    public static function file(
        string $body,
        string $filename,
        string $contentType = 'application/octet-stream',
        int $status = 200,
        bool $attachment = true,
        bool $withContentLength = true,
        array $headers = []
    ): self {
        $disposition = $attachment ? 'attachment' : 'inline';

        $defaultHeaders = [
            'Content-Type' => $contentType,
            'Content-Disposition' => self::contentDisposition($disposition, $filename),
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($withContentLength) {
            $defaultHeaders['Content-Length'] = (string)strlen($body);
        }

        return self::raw($body, $status, array_merge($defaultHeaders, $headers));
    }

    /**
     * Convenience alias for attachment file responses.
     *
     * @param array<string,string> $headers
     */
    public static function download(
        string $body,
        string $filename,
        string $contentType = 'application/octet-stream',
        int $status = 200,
        array $headers = []
    ): self {
        return self::file(
            body: $body,
            filename: $filename,
            contentType: $contentType,
            status: $status,
            attachment: true,
            withContentLength: true,
            headers: $headers
        );
    }

    public function withHeader(string $name, string $value): self
    {
        $headers = self::normalizeHeaders([$name => $value]);
        $clone = clone $this;
        foreach ($headers as $headerName => $headerValue) {
            $clone->headers[$headerName] = $headerValue;
        }
        return $clone;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header($k . ': ' . $v);
        }
        echo $this->body;
    }

    /**
     * @param array<string,string> $headers
     * @return array<string,string>
     */
    private static function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $name = trim((string)$name);
            $value = (string)$value;

            if ($name === '' || preg_match('/[\r\n:]/', $name) === 1) {
                throw new \InvalidArgumentException('Invalid HTTP header name');
            }
            if (preg_match('/[\r\n]/', $value) === 1) {
                throw new \InvalidArgumentException('Invalid HTTP header value');
            }

            $normalized[$name] = $value;
        }

        return $normalized;
    }

    private static function contentDisposition(string $disposition, string $filename): string
    {
        if (!in_array($disposition, ['attachment', 'inline'], true)) {
            throw new \InvalidArgumentException('Invalid content disposition');
        }

        $filename = self::safeDownloadFilename($filename);
        $fallback = self::asciiFilenameFallback($filename);
        $encoded = rawurlencode($filename);

        return $disposition . '; filename="' . self::quoteHeaderValue($fallback) . '"; filename*=UTF-8\'\'' . $encoded;
    }

    private static function safeDownloadFilename(string $filename): string
    {
        $filename = trim(str_replace(['\\', '/'], '_', $filename));
        $filename = preg_replace('/[\x00-\x1F\x7F]+/', '', $filename) ?? '';
        $filename = trim($filename, " ._\t\n\r\0\x0B");

        return $filename !== '' ? $filename : 'download';
    }

    private static function asciiFilenameFallback(string $filename): string
    {
        $fallback = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $filename) ?? '';
        $fallback = trim($fallback, " ._\t\n\r\0\x0B");

        return $fallback !== '' ? $fallback : 'download';
    }

    private static function quoteHeaderValue(string $value): string
    {
        return addcslashes($value, "\\\"");
    }
}
