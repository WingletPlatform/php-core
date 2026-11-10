<?php
declare(strict_types=1);

namespace Winglet\Core\Http\Exception;

use Psr\Log\LoggerInterface;
use Throwable;
use Winglet\Core\Http\Context\RequestContext;
use Winglet\Core\Http\Input\InputValidationException;
use Winglet\Core\Http\Request;
use Winglet\Core\Http\Response;
use Winglet\Core\Support\Config;

final class ExceptionHandler
{
    public function __construct(
        private LoggerInterface $logger,
        private ?Config $config = null,
        private ?ApiExceptionMapperInterface $mapper = null,
        private ?ApiErrorResponseFactoryInterface $responseFactory = null,
    ) {}

    public function handle(Throwable $exception, ?Request $request = null): Response
    {
        $requestContext = $request?->getAttribute('request_context');
        if (!$requestContext instanceof RequestContext) {
            $requestContext = RequestContext::unavailable();
        }

        $logContext = [
            'exception' => $exception,
            'request_id' => $requestContext->requestId !== '' ? $requestContext->requestId : null,
            'correlation_id' => $requestContext->correlationId !== '' ? $requestContext->correlationId : null,
        ];

        if ($exception instanceof HttpException && $exception->context !== []) {
            $logContext = array_merge($logContext, $exception->context);
        }

        $this->logger->error($exception->getMessage(), array_filter(
            $logContext,
            static fn (mixed $value): bool => $value !== null,
        ));

        if ($request !== null && $this->mapper !== null) {
            $mapped = $this->mapper->map($exception, $request);
            if ($mapped !== null) {
                return $this->factory()->create($mapped, $requestContext);
            }
        }

        // Preserve the established Winglet validation response fields.
        if ($exception instanceof InputValidationException) {
            return Response::json(array_filter([
                'error' => 'ValidationError',
                'message' => 'Validation failed',
                'errors' => $exception->errors,
                'correlationId' => $requestContext->correlationId !== ''
                    ? $requestContext->correlationId
                    : null,
            ], static fn (mixed $value): bool => $value !== null), 400);
        }

        $status = $exception instanceof HttpException ? $exception->statusCode : 500;
        $environment = $this->config?->get('app.env', 'dev') ?? ($_ENV['APP_ENV'] ?? 'dev');
        $message = $environment === 'prod'
            ? $this->publicMessageForStatus($status)
            : ($exception->getMessage() !== ''
                ? $exception->getMessage()
                : $this->publicMessageForStatus($status));

        return $this->factory()->create(new ApiError(
            status: $status,
            code: $this->publicErrorCodeForStatus($status, $exception),
            message: $message,
        ), $requestContext);
    }

    private function factory(): ApiErrorResponseFactoryInterface
    {
        return $this->responseFactory ?? new DefaultApiErrorResponseFactory();
    }

    private function publicMessageForStatus(int $status): string
    {
        return match (true) {
            $status === 400 => 'Bad request',
            $status === 401 => 'Unauthorized',
            $status === 403 => 'Forbidden',
            $status === 404 => 'Not found',
            $status === 405 => 'Method not allowed',
            $status >= 500 => 'Internal server error',
            default => 'Error',
        };
    }

    private function publicErrorCodeForStatus(int $status, Throwable $exception): string
    {
        return match (true) {
            $status === 400 => 'BadRequest',
            $status === 401 => 'Unauthorized',
            $status === 403 => 'Forbidden',
            $status === 404 => 'NotFound',
            $status === 405 => 'MethodNotAllowed',
            $status >= 500 => 'ServerError',
            default => (new \ReflectionClass($exception))->getShortName(),
        };
    }
}
