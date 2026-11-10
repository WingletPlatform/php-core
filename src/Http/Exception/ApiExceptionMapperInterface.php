<?php
declare(strict_types=1);

namespace Winglet\Core\Http\Exception;

use Throwable;
use Winglet\Core\Http\Request;

interface ApiExceptionMapperInterface
{
    public function map(Throwable $exception, Request $request): ?ApiError;
}
