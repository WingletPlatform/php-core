<?php
declare(strict_types=1);

namespace Winglet\Core\Container;

use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * Thrown when a container entry cannot be found (PSR-11).
 */
final class NotFoundException extends RuntimeException implements NotFoundExceptionInterface
{
}
