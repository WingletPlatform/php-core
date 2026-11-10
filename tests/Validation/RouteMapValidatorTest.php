<?php
declare(strict_types=1);

namespace Winglet\Core\Tests\Validation;

use Winglet\Core\Container\ServiceContainer;
use Winglet\Core\Tests\TestCase;
use Winglet\Core\Validation\RouteMapValidator;

final class ValidRouteController
{
    public function show(string $id): void {}
}

final class InvalidRouteController
{
    public function show(string $id): void {}
}

final class RouteMapValidatorTest extends TestCase
{
    public function testValidateMapAcceptsRouteWithExistingControllerActionAndParam(): void
    {
        $map = [
            'static' => [],
            'dynamic' => [
                'GET' => [[
                    'path' => '/items/{id}',
                    'controller' => ValidRouteController::class,
                    'action' => 'show',
                    'params' => ['id'],
                ]],
            ],
        ];

        $errors = (new RouteMapValidator())->validateMap($map, new ServiceContainer());

        self::assertSame([], $errors);
    }

    public function testValidateMapReportsMissingRequiredRouteParam(): void
    {
        $map = [
            'static' => [
                'GET' => [
                    '/items' => [
                        'controller' => InvalidRouteController::class,
                        'action' => 'show',
                    ],
                ],
            ],
            'dynamic' => [],
        ];

        $errors = (new RouteMapValidator())->validateMap($map, new ServiceContainer());

        self::assertCount(1, $errors);
        self::assertStringContainsString('$id', $errors[0]->message);
    }
}
