<?php
declare(strict_types=1);

namespace Winglet\Core\Http\Controller;

use Winglet\Core\Http\Request;
use Winglet\Core\Http\Routing\Attributes\Route;

final class EchoController extends AbstractController
{
    #[Route('/echo')]
    /** @return array<string,mixed> */
    public function handle(Request $request): array
    {
        return [
            'method' => $request->method,
            'path' => $request->path,
            'query' => $request->query,
            'post' => $request->post,
            'json' => $request->json,
        ];
    }
}