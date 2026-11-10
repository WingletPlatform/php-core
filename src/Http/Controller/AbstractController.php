<?php
declare(strict_types=1);

/**
 * Base controller with common helpers.
 *
 * Responsibilities:
 * - Provides convenience response helpers (json()).
 *
 * Extended by:
 * - Framework or application controllers.
 */

namespace Winglet\Core\Http\Controller;

use Winglet\Core\Http\Response;

abstract class AbstractController implements ControllerInterface
{
    /** @param array<string,mixed> $data */
    protected function json(array $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    /** @param array<string,string> $headers */
    protected function raw(string $body, int $status = 200, array $headers = []): Response
    {
        return Response::raw($body, $status, $headers);
    }

    /** @param array<string,string> $headers */
    protected function file(
        string $body,
        string $filename,
        string $contentType = 'application/octet-stream',
        int $status = 200,
        bool $attachment = true,
        array $headers = []
    ): Response {
        return Response::file(
            body: $body,
            filename: $filename,
            contentType: $contentType,
            status: $status,
            attachment: $attachment,
            headers: $headers
        );
    }

    /** @param array<string,string> $headers */
    protected function download(
        string $body,
        string $filename,
        string $contentType = 'application/octet-stream',
        int $status = 200,
        array $headers = []
    ): Response {
        return Response::download($body, $filename, $contentType, $status, $headers);
    }
}