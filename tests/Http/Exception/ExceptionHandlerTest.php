<?php
declare(strict_types=1);

namespace Winglet\Core\Tests\Http\Exception;

use RuntimeException;
use Throwable;
use Winglet\Core\Http\Context\RequestContext;
use Winglet\Core\Http\Exception\ApiError;
use Winglet\Core\Http\Exception\ApiErrorResponseFactoryInterface;
use Winglet\Core\Http\Exception\ApiExceptionMapperInterface;
use Winglet\Core\Http\Exception\ExceptionHandler;
use Winglet\Core\Http\Exception\HttpException;
use Winglet\Core\Http\Input\InputValidationException;
use Winglet\Core\Http\Request;
use Winglet\Core\Http\Response;
use Winglet\Core\Support\Config;
use Winglet\Core\Tests\Support\ArrayLogger;
use Winglet\Core\Tests\TestCase;

final class ExceptionHandlerTest extends TestCase
{
    public function testProductionResponseHidesUnexpectedExceptionAndIncludesCorrelationId(): void
    {
        $logger = new ArrayLogger();
        $handler = new ExceptionHandler($logger, $this->config('prod'));
        $request = $this->makeRequest('GET', '/failure')->withAttribute(
            'request_context',
            new RequestContext('req-1', 'corr-1')
        );

        $response = $handler->handle(new RuntimeException('database password leaked'), $request);
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(500, $response->status);
        self::assertSame('ServerError', $body['error']);
        self::assertSame('Internal server error', $body['message']);
        self::assertSame('corr-1', $body['correlationId']);
        self::assertStringNotContainsString('password', $response->body);
        self::assertSame('corr-1', $logger->lastRecord()['context']['correlation_id']);
    }

    public function testDevelopmentResponseKeepsDiagnosticMessage(): void
    {
        $response = (new ExceptionHandler(new ArrayLogger(), $this->config('dev')))->handle(
            new RuntimeException('diagnostic message'),
            $this->makeRequest('GET', '/failure')
        );

        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('diagnostic message', $body['message']);
    }

    public function testApplicationMapperAndResponseFactoryAreUsed(): void
    {
        $mapper = new class implements ApiExceptionMapperInterface {
            public function map(Throwable $exception, Request $request): ?ApiError
            {
                return $exception instanceof DomainFailure
                    ? new ApiError(409, 'DOMAIN_CONFLICT', 'Conflict', ['field' => 'name'], ['Retry-After' => '1'])
                    : null;
            }
        };
        $factory = new class implements ApiErrorResponseFactoryInterface {
            public bool $called = false;
            public function create(ApiError $error, RequestContext $context): Response
            {
                $this->called = true;
                return Response::json(['code' => $error->code, 'correlation' => $context->correlationId], $error->status, $error->headers);
            }
        };
        $request = $this->makeRequest('POST', '/domain')->withAttribute(
            'request_context',
            new RequestContext('req-2', 'corr-2')
        );

        $response = (new ExceptionHandler(new ArrayLogger(), $this->config('prod'), $mapper, $factory))
            ->handle(new DomainFailure('internal'), $request);

        self::assertTrue($factory->called);
        self::assertSame(409, $response->status);
        self::assertSame('1', $response->headers['Retry-After']);
        self::assertSame(['code' => 'DOMAIN_CONFLICT', 'correlation' => 'corr-2'], json_decode($response->body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testValidationResponsePreservesLegacyShape(): void
    {
        $request = $this->makeRequest('POST', '/validate')->withAttribute(
            'request_context',
            new RequestContext('req-3', 'corr-3')
        );
        $errors = [['field' => 'email', 'code' => 'required']];

        $response = (new ExceptionHandler(new ArrayLogger(), $this->config('prod')))
            ->handle(new InputValidationException($errors), $request);
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(400, $response->status);
        self::assertSame('ValidationError', $body['error']);
        self::assertSame('Validation failed', $body['message']);
        self::assertSame($errors, $body['errors']);
        self::assertSame('corr-3', $body['correlationId']);
    }

    public function testProductionHttpExceptionReturnsSafeStatusMessageAndLogsInternalContext(): void
    {
        $logger = new ArrayLogger();
        $response = (new ExceptionHandler($logger, $this->config('prod')))->handle(
            new HttpException(404, 'internal resource details', ['resource_id' => 'abc']),
            $this->makeRequest('GET', '/missing')
        );
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(404, $response->status);
        self::assertSame('Not found', $body['message']);
        self::assertSame('abc', $logger->lastRecord()['context']['resource_id']);
    }

    private function config(string $environment): Config
    {
        $path = tempnam(sys_get_temp_dir(), 'winglet-config-');
        self::assertNotFalse($path);
        file_put_contents($path, '<?php return ' . var_export(['app' => ['env' => $environment]], true) . ';');
        $config = Config::fromPhpFile($path);
        unlink($path);
        return $config;
    }
}

final class DomainFailure extends RuntimeException {}
