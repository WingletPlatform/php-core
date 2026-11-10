<?php
declare(strict_types=1);

namespace Winglet\Core\Http\Input;

use Winglet\Core\Http\Exception\HttpException;

/**
 * Thrown when request input validation fails.
 *
 * ExceptionHandler maps this to HTTP 400 and returns the errors list.
 */
final class InputValidationException extends HttpException
{
    /** @param list<array<string,mixed>> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(400, 'Validation failed');
    }
}
