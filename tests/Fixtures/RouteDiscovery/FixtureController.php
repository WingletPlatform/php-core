<?php
declare(strict_types=1);

namespace Winglet\Core\Tests\Fixtures\RouteDiscovery;

use Winglet\Core\Http\Controller\AbstractController;
use Winglet\Core\Http\Response;
use Winglet\Core\Http\Routing\Attributes\Route;

final class FixtureController extends AbstractController
{
    #[Route('/fixture/static', methods: ['GET'])]
    public function staticRoute(): Response
    {
        return $this->json(['ok' => true]);
    }

    #[Route('/fixture/items/{itemId}', methods: ['GET'])]
    public function dynamicRoute(string $itemId): Response
    {
        return $this->json(['itemId' => $itemId]);
    }
}
