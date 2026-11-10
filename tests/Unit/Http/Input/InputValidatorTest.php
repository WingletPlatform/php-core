<?php
declare(strict_types=1);

namespace Winglet\Core\Tests\Unit\Http\Input;

use Winglet\Core\Http\Input\Field;
use Winglet\Core\Http\Input\Input;
use Winglet\Core\Http\Input\InputValidationException;
use Winglet\Core\Http\Input\Requirements;
use Winglet\Core\Http\Input\Validators;
use Winglet\Core\Tests\TestCase;

final class InputValidatorTest extends TestCase
{
    public function testConvertsAndValidatesCommonScalarTypes(): void
    {
        $input = Input::from($this->makeRequest(
            method: 'POST',
            path: '/input',
            post: [
                'count' => '12',
                'price' => '12,50',
                'enabled' => 'yes',
                'email' => 'person@example.test',
            ],
        ))
            ->required('count', 'int', ['validators' => [Validators::between(1, 20)]])
            ->required('price', 'float')
            ->required('enabled', 'bool')
            ->required('email', 'string', ['validators' => [Validators::email()]])
            ->validate();

        self::assertSame(12, $input->get('count'));
        self::assertSame(12.5, $input->get('price'));
        self::assertTrue($input->get('enabled'));
        self::assertSame('person@example.test', $input->get('email'));
    }

    public function testReportsStableErrorsForMissingAndInvalidFields(): void
    {
        try {
            Input::from($this->makeRequest(
                method: 'POST',
                path: '/input',
                post: ['age' => 'not-a-number'],
            ))
                ->required('name')
                ->required('age', 'int')
                ->validate();
            self::fail('Expected validation to fail.');
        } catch (InputValidationException $e) {
            self::assertSame([
                ['field' => 'name', 'code' => 'missing', 'message' => 'Field is required'],
                ['field' => 'age', 'code' => 'invalid_format', 'message' => 'Invalid field format'],
            ], $e->errors);
        }
    }

    public function testPresenceRuleSelectsSmallestMissingBranch(): void
    {
        try {
            Input::from($this->makeRequest('POST', '/input'))
                ->optional('email')
                ->optional('phone')
                ->optional('country_code')
                ->requireRule(Requirements::anyOf(
                    Requirements::var('email'),
                    Requirements::allOf(
                        Requirements::var('phone'),
                        Requirements::var('country_code'),
                    ),
                ))
                ->validate();
            self::fail('Expected validation to fail.');
        } catch (InputValidationException $e) {
            self::assertSame(['email'], $e->errors[0]['missing']);
        }
    }

    public function testConvertsTypedArraysAndMaps(): void
    {
        $input = Input::from($this->makeRequest(
            method: 'POST',
            path: '/input',
            post: [
                'ids' => ['1', '2'],
                'flags' => ['first' => 'true', 'second' => 'false'],
            ],
        ))
            ->required('ids', 'array', ['items' => Field::optional('item', 'int')])
            ->required('flags', 'map', [
                'key' => Field::optional('key', 'string'),
                'value' => Field::optional('value', 'bool'),
            ])
            ->validate();

        self::assertSame([1, 2], $input->get('ids'));
        self::assertSame(['first' => true, 'second' => false], $input->get('flags'));
    }
}
