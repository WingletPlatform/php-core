<?php
declare(strict_types=1);

namespace Winglet\Core\Http\Controller;

use Winglet\Core\Http\Request;
use Winglet\Core\Http\Routing\Attributes\Route;

final class HealthController extends AbstractController
{
    #[Route('/health')]
    /** @return array<string,mixed> */
    public function handleGet(Request $request): array
    {
        return ['ok' => true];
    }
}