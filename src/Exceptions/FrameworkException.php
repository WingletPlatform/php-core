<?php
declare(strict_types=1);

/**
 * Base framework exception type.
 *
 * Responsibilities:
 * - Provides a shared parent for framework-specific errors.
 *
 * Extended by:
 * - ConfigurationException, RuntimeException, etc.
 */


namespace Winglet\Core\Exceptions;

class FrameworkException extends \RuntimeException {}
