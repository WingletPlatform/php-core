<?php
declare(strict_types=1);

namespace Winglet\Core\Cli;

use Winglet\Core\Kernel\Bootstrap\Application;

interface CommandInterface
{
    public function getName(): string;

    public function getDescription(): string;

    public function getHelp(): ?string;

    /**
     * @param list<string> $args
     */
    public function run(array $args, Application $app): int;
}
