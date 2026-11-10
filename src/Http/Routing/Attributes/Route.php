<?php
declare(strict_types=1);

namespace Winglet\Core\Http\Routing\Attributes;

use Attribute;

/**
 * Route attribute controller-metodeille.
 *
 * Esim:
 *   #[Route('/cars/{id}/update')]
 *   #[Route('/health')]
 *
 * methods on optional; jos tyhjä, metodi päätellään handleGet/handlePost/handle perusteella.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Route
{
    /**
     * @param string[] $methods
     */
    public function __construct(
        public readonly string $path,
        public readonly array $methods = [],
    ) {}
}
