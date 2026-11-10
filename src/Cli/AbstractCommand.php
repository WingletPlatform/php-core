<?php
declare(strict_types=1);

namespace Winglet\Core\Cli;

abstract class AbstractCommand implements CommandInterface
{
    public function getHelp(): ?string
    {
        return null;
    }
}
