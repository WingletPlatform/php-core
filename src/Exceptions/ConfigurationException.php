<?php
declare(strict_types=1);

/**
 * Configuration error exception.
 *
 * Responsibilities:
 * - Thrown when configuration cannot be loaded or is invalid.
 *
 * Thrown from:
 * - Config::fromPhpFile() when config file does not return an array.
 */


namespace Winglet\Core\Exceptions;

final class ConfigurationException extends FrameworkException {}
