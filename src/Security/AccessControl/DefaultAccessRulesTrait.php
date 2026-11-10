<?php
declare(strict_types=1);

namespace Winglet\Core\Security\AccessControl;

/**
 * Convenience defaults for ControllerAccessRulesInterface.
 *
 * Use in controller:
 * - implements ControllerAccessRulesInterface
 * - use DefaultAccessRulesTrait;
 */
// @phpstan-ignore-next-line trait.unused
trait DefaultAccessRulesTrait
{
    public function getRequiredSessionLevel(): ?int { return null; }

    public function getRequiredGroups(): array { return []; }

    public function customAccessRule(): bool { return true; }
}