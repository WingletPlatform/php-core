<?php
declare(strict_types=1);

// Backwards-compatibility shim.
// The Router class was previously (incorrectly) located in Route.php, which broke PSR-4.
// Keep this file so any old manual includes keep working.

require_once __DIR__ . '/Router.php';