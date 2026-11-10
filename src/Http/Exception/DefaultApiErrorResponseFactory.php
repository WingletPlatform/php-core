<?php
declare(strict_types=1);

namespace Winglet\Core\Http\Exception;

use Winglet\Core\Http\Context\RequestContext;
use Winglet\Core\Http\Response;

final class DefaultApiErrorResponseFactory implements ApiErrorResponseFactoryInterface
{
    public function create(ApiError $error, RequestContext $context): Response
    {
        return Response::json(array_filter([
            'error' => $error->code,
            'message' => $error->message,
            'details' => $error->details !== [] ? $error->details : null,
            'correlationId' => $context->correlationId !== '' ? $context->correlationId : null,
        ], static fn (mixed $value): bool => $value !== null), $error->status, $error->headers);
    }
}
