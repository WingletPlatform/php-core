<?php
declare(strict_types=1);

namespace Winglet\Core\Http\Controller;

use Winglet\Core\Health\ReadinessCheckRunner;
use Winglet\Core\Http\Response;
use Winglet\Core\Http\Routing\Attributes\Route;

final class ReadinessController extends AbstractController
{
    public function __construct(private ReadinessCheckRunner $runner) {}

    #[Route('/readiness')]
    public function handleGet(): Response
    {
        $result = $this->runner->run();

        return Response::json($result, $result['ready'] ? 200 : 503);
    }
}
