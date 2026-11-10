<?php
declare(strict_types=1);

namespace Winglet\Core\Security\AccessControl;

/**
 * Optional controller contract for defining access requirements.
 *
 * Implement on controllers that need authorization rules.
 * Used by:
 * - ControllerInvoker -> AccessDecisionManager
 */
interface ControllerAccessRulesInterface
{
    public function getRequiredSessionLevel(): ?int;

    /** @return string[] */
    public function getRequiredGroups(): array;

    /**
     * Custom rule hook for special cases.
     * Return true to allow, false to deny.
     */
    public function customAccessRule(): bool;
}