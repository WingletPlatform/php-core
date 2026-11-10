<?php
declare(strict_types=1);

namespace Winglet\Core\Cli;

use Winglet\Core\Container\ServiceContainer;
use Winglet\Core\Kernel\Bootstrap\Application;

final class Runner
{
    public function __construct(private readonly Application $app) {}

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $container = $this->app->container;
        if (!$container instanceof ServiceContainer) {
            fwrite(STDERR, "Container does not support CLI command loading.\n");
            return 1;
        }

        (new AppCommandLoader())->loadIfPresent($this->app->basePath, $container);
        $commands = $this->commands($container);

        $name = $argv[1] ?? 'list';
        if ($name === '-h' || $name === '--help') {
            $name = 'help';
        }

        if ($name === 'list') {
            return $this->renderList($argv[0] ?? 'winglet', $commands);
        }

        if ($name === 'help') {
            return $this->renderHelp($argv[0] ?? 'winglet', $commands, $argv[2] ?? null);
        }

        foreach ($commands as $command) {
            if ($command->getName() === $name) {
                return $command->run(array_slice($argv, 2), $this->app);
            }
        }

        fwrite(STDERR, "Unknown command: {$name}\n\n");
        return $this->renderList($argv[0] ?? 'winglet', $commands, stderr: true, exitCode: 1);
    }

    /**
     * @return list<CommandInterface>
     */
    private function commands(ServiceContainer $container): array
    {
        $commands = $container->getTaggedAs('cli.command', CommandInterface::class);
        usort($commands, static fn (CommandInterface $a, CommandInterface $b): int => strcmp($a->getName(), $b->getName()));
        return $commands;
    }

    /**
     * @param list<CommandInterface> $commands
     */
    private function renderList(string $script, array $commands, bool $stderr = false, int $exitCode = 0): int
    {
        $out = $stderr ? STDERR : STDOUT;
        fwrite($out, "Winglet CLI\n\n");
        fwrite($out, "Usage:\n  {$script} <command> [args]\n\n");
        fwrite($out, "Commands:\n");

        foreach ($commands as $command) {
            fwrite($out, sprintf("  %-36s %s\n", $command->getName(), $command->getDescription()));
        }

        fwrite($out, sprintf("  %-36s %s\n", 'list', 'List available commands'));
        fwrite($out, sprintf("  %-36s %s\n", 'help [command]', 'Show command help'));

        return $exitCode;
    }

    /**
     * @param list<CommandInterface> $commands
     */
    private function renderHelp(string $script, array $commands, ?string $commandName): int
    {
        if ($commandName === null || trim($commandName) === '') {
            return $this->renderList($script, $commands);
        }

        foreach ($commands as $command) {
            if ($command->getName() !== $commandName) {
                continue;
            }

            fwrite(STDOUT, $command->getName() . "\n\n");
            fwrite(STDOUT, $command->getDescription() . "\n\n");
            $help = $command->getHelp();
            if (is_string($help) && trim($help) !== '') {
                fwrite(STDOUT, trim($help) . "\n");
            } else {
                fwrite(STDOUT, "Usage:\n  {$script} {$command->getName()}\n");
            }
            return 0;
        }

        fwrite(STDERR, "Unknown command: {$commandName}\n");
        return 1;
    }
}
