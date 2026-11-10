<?php
declare(strict_types=1);

/**
 * Generic runtime exception for framework failures.
 *
 * Responsibilities:
 * - Used for unexpected runtime conditions (missing controllers/actions, etc.).
 *
 * Thrown from:
 * - ControllerInvoker and other core components as needed.
 */


namespace Winglet\Core\Exceptions;

final class RuntimeException extends FrameworkException {}
