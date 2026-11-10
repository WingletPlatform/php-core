<?php
declare(strict_types=1);

namespace Winglet\Core\Http\Controller;

use Winglet\Core\Http\Request;
use Winglet\Core\Http\Response;
use Winglet\Core\Kernel\Context\AppContext;
use Winglet\Core\Application\Action\CreateExampleEnum;

/**
 * Example controller showing how to call an action that uses AppContext.
 */
final class ExampleEnumController extends AbstractController
{
    public function __construct(private AppContext $ctx) {}

    public function create(Request $request): Response
    {
        // Assume JSON body: { "name": "COMPANY" }
        $name = (string)($request->json['name'] ?? '');

        if ($name === '') {
            return Response::json(['error' => 'name required'], 400);
        }

        $this->ctx->log->info("Creating enum {$name}");

        $action = new CreateExampleEnum($this->ctx);
        $entity = $action->run($name);

        // If your entities extend AbstractEntity:
        // return Response::json($entity->exportArray([], false), 201);

        return Response::json([
            'id' => method_exists($entity, 'getId') ? $entity->getId() : null,
            'name' => $name,
        ], 201);
    }
}