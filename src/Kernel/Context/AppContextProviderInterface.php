<?php
declare(strict_types=1);

namespace Winglet\Core\Kernel\Context;

use Winglet\Core\Http\Request;

interface AppContextProviderInterface
{
    public function create(Request $request): AppContext;
}
