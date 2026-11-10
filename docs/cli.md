# Winglet CLI

Winglet Core now provides a general CLI entrypoint for app-specific commands.

## Entry point

Inside a running container:

```bash
winglet list
winglet help
winglet help app:command
```

The wrapper points to:

```bash
php /var/www/App/vendor/winglet/core/bin/console
```

## Registering commands in an app

Add one of these files:

- `bootstrap/commands.php`
- `App/bootstrap/commands.php`
- `App/src/Bootstrap/commands.php`

The file may return either:

- `callable(ServiceContainer $c): void`
- `list<class-string<CommandInterface>|CommandInterface>`

Example:

```php
<?php

declare(strict_types=1);

use App\Cli\ExampleCommand;

return [
    ExampleCommand::class,
];
```

Each command must implement `Winglet\Core\Cli\CommandInterface`.

## Built-in meta commands

- `list`
- `help [command]`
