<?php
declare(strict_types=1);

require '/var/www/App/vendor/autoload.php';

use Winglet\Core\Kernel\HttpKernel;
use Winglet\Core\Kernel\Bootstrap\Bootstrapper;

$basePath = getenv('WINGLET_APP_PATH') ?: dirname(__DIR__);

$bootstrapper = new Bootstrapper(basePath: $basePath);
$kernel = new HttpKernel($bootstrapper);

$response = $kernel->handleGlobals();
$response->send();
