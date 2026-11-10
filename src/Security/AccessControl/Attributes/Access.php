<?php
declare(strict_types=1);

namespace Winglet\Core\Security\AccessControl\Attributes;

use Attribute;

/**
 * Access attribute controller-metodeille.
 *
 * minLevel/maxLevel: sessionLevel rajat (null = ei rajaa)
 * groups: sallitut ryhmät (tyhjä = ei ryhmärajaa)
 * checker: lisätarkastusluokka (esim. item-ownership tms.)
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Access
{
    /**
     * @param string[] $groups
     */
    public function __construct(
        public readonly ?int $minLevel = null,
        public readonly ?int $maxLevel = null,
        public readonly array $groups = [],
        public readonly ?string $checker = null, // FQCN
    ) {}
}
