<?php
declare(strict_types=1);

namespace Winglet\Core\Http\Exception;

use Winglet\Core\Http\Context\RequestContext;
use Winglet\Core\Http\Response;

interface ApiErrorResponseFactoryInterface
{
    public function create(ApiError $error, RequestContext $context): Response;
}
