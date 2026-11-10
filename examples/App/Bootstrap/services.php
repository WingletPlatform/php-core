<?php
declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Winglet\Core\Container\ServiceContainer;

return function (ContainerInterface $c): void {
    // If you want the extended helpers (alias/bind/singleton), you can type-check:
    if ($c instanceof ServiceContainer) {
        // Interface -> implementation alias example
        // $c->alias(App\Contracts\Mailer::class, App\Service\SesMailer::class);

        // Explicit singleton factory example
        // $c->singleton(App\Service\SesMailer::class, fn(ContainerInterface $c) =>
        //     new App\Service\SesMailer($c->get(LoggerInterface::class), /* ... */)
        // );
    }

    // Simple bindings still work via set():
    // $c->set('app.feature_x.enabled', true);
};
