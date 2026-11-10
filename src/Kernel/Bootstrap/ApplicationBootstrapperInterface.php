<?php
declare(strict_types=1);

namespace Winglet\Core\Kernel\Bootstrap;

interface ApplicationBootstrapperInterface
{
    public function boot(): Application;
}
