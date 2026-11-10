<?php
declare(strict_types=1);

namespace Winglet\Core\Http\Exception;

use Throwable;
use Winglet\Core\Http\Request;

final class NullApiExceptionMapper implements ApiExceptionMapperInterface
{
    public function map(Throwable $exception, Request $request): ?ApiError
    {
        return null;
    }
}
